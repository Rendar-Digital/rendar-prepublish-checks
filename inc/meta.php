<?php
/**
 * Post meta this plugin owns.
 *
 * Nothing here is block content, and nothing here is required for a post to
 * render. Deactivate the plugin and every one of these rows becomes an inert
 * orphan — which is the whole reason the decorative marker lives in meta rather
 * than as an attribute on core/image.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the editor-facing meta.
 *
 * @return void
 */
function rendar_pc_register_meta() {
	foreach ( rendar_pc_get_post_types() as $post_type ) {
		register_post_meta(
			$post_type,
			RENDAR_PC_META_DECORATIVE,
			array(
				'single'            => true,
				'type'              => 'array',
				'description'       => __( 'Images in this article the author has marked as decorative, so they need no alt text.', 'rendar-prepublish-checks' ),
				'default'           => array(),
				'sanitize_callback' => 'rendar_pc_sanitize_decorative',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Override write path -- enforcement only. While enforcement is locked off the override meta is
		// not registered at all, so there is no REST-writable field to arm a
		// publish bypass against while the gate is dark. See
		// rendar_pc_enforcement_enabled() in the main plugin file.
		if ( ! rendar_pc_enforcement_enabled() ) {
			continue;
		}

		register_post_meta(
			$post_type,
			RENDAR_PC_META_OVERRIDE,
			array(
				'single'            => true,
				'type'              => 'string',
				'description'       => __( 'A one-shot authorization to publish past failing checks, consumed by the save it accompanies.', 'rendar-prepublish-checks' ),
				'default'           => '',
				'sanitize_callback' => 'rendar_pc_sanitize_override',
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					// Writing this field needs only edit access to the post —
					// the same bar as the decorative marker above. Gating it on
					// the override capability instead 403'd an ordinary author's
					// save: the block editor re-sends every registered meta key
					// (including an untouched, empty override) on every publish,
					// and core checks a field's auth_callback before the no-op
					// short-circuit, so a non-privileged author was refused a
					// save whose checks were passing. The capability that
					// actually decides whether an override is *honoured* lives in
					// Rendar_PC_Context::resolve_override() — the real gate —
					// so a token written here by someone without manage_options
					// is inert.
					return current_user_can( 'edit_post', $post_id );
				},
				'show_in_rest'      => array(
					'schema' => array( 'type' => 'string' ),
				),
			)
		);
	}
}
// CPTs registered on init at the default or a later priority must exist before
// scope is resolved. Bound: registrations on init before PHP_INT_MAX are covered;
// types registered after init (or later at the same max priority) are not.
// REST schema construction occurs after init, so meta is registered in time.
add_action( 'init', 'rendar_pc_register_meta', PHP_INT_MAX );

/**
 * Sanitize the decorative acknowledgment list.
 *
 * @param mixed $value Raw value.
 * @return string[]
 */
function rendar_pc_sanitize_decorative( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$clean = array();

	foreach ( $value as $item ) {
		if ( ! is_string( $item ) ) {
			continue;
		}

		// Keys are produced by rendar_pc_image_key(): "id:<int>" or
		// "src:<md5>". Anything else is not something this plugin wrote.
		if ( preg_match( '/^id:[1-9][0-9]*$/', $item ) || preg_match( '/^src:[a-f0-9]{32}$/', $item ) ) {
			$clean[] = $item;
		}
	}

	return array_values( array_unique( $clean ) );
}

/**
 * Sanitize the override token.
 *
 * Stored as JSON rather than an object meta so the "no override" state is an
 * empty string rather than an ambiguous empty array.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function rendar_pc_sanitize_override( $value ) {
	// A non-admin's empty meta still needs to round-trip through REST, but a
	// forged non-empty token must never be persisted for a later admin save.
	if ( ! current_user_can( 'manage_options' ) ) {
		return '';
	}

	$normalized = rendar_pc_normalize_override( $value );

	if ( ! $normalized ) {
		return '';
	}

	$normalized['reason'] = mb_substr( $normalized['reason'], 0, 500 );

	return wp_json_encode( $normalized );
}

/**
 * Record the first time a post is published.
 *
 * This is what lets the gate leave existing articles alone: a post that has
 * been published before is edited under advisory rules, a post that has not is
 * gated. Backfilled lazily for posts that predate the plugin.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_record_first_publish( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	if ( ! in_array( $new_status, array( 'publish', 'private' ), true ) ) {
		return;
	}

	// A scheduled post that failed its re-check is on its way back to pending.
	// Recording it as published would make every later edit look like an edit to
	// a live article, which is precisely the state that switches the gate off.
	if ( function_exists( 'rendar_pc_was_reverted' ) && rendar_pc_was_reverted( $post->ID ) ) {
		return;
	}

	if ( get_post_meta( $post->ID, RENDAR_PC_META_FIRST_PUBLISHED, true ) ) {
		return;
	}

	update_post_meta( $post->ID, RENDAR_PC_META_FIRST_PUBLISHED, current_time( 'mysql', true ) );
}
// Enforcement-support write path: the first-published watermark only matters to
// the gate's advisory-vs-gated decision, which does not run while enforcement is locked off. Gated
// off so no publish-time meta write is registered while enforcement is dark.
if ( rendar_pc_enforcement_enabled() ) {
	add_action( 'transition_post_status', 'rendar_pc_record_first_publish', 20, 3 );
}

/**
 * Append an entry to a post's override audit log.
 *
 * @param int   $post_id Post ID.
 * @param array $entry   Log entry.
 * @return void
 */
function rendar_pc_append_override_log( $post_id, array $entry ) {
	$log = get_post_meta( $post_id, RENDAR_PC_META_OVERRIDE_LOG, true );

	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$log[] = $entry;

	// Bounded so a pathological loop cannot grow one meta row without limit.
	if ( count( $log ) > 50 ) {
		$log = array_slice( $log, -50 );
	}

	update_post_meta( $post_id, RENDAR_PC_META_OVERRIDE_LOG, $log );
}

/**
 * Read a post's override audit log.
 *
 * @param int $post_id Post ID.
 * @return array[]
 */
function rendar_pc_get_override_log( $post_id ) {
	$log = get_post_meta( $post_id, RENDAR_PC_META_OVERRIDE_LOG, true );

	return is_array( $log ) ? $log : array();
}
