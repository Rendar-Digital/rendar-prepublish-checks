<?php
/**
 * The advisory REST route.
 *
 * The editor sends its current, unsaved state here and gets back exactly what
 * the gate would decide about it. The panel deliberately computes nothing
 * itself: a second implementation in JavaScript would drift from this one, and
 * the first time it did, an author would be told their article was fine and
 * then refused at publish.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register routes.
 *
 * @return void
 */
function rendar_pc_register_routes() {
	register_rest_route(
		RENDAR_PC_REST_NS,
		'/evaluate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'rendar_pc_rest_evaluate',
			'permission_callback' => 'rendar_pc_rest_permission',
			'args'                => array(
				'post_id'        => array(
					'type'     => 'integer',
					'required' => true,
				),
				'post_type'      => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'content'        => array( 'type' => 'string' ),
				'featured_media' => array( 'type' => 'integer' ),
				'categories'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'tags'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'decorative'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				// Nothing sends this today — the panel currently asks nothing
				// about ACF field values (inc/assets.php's useEvaluation() has
				// no ACF-aware state to send). Accepted here for the same
				// reason inc/gate.php now reads it: symmetry with the gate's
				// override shape, and a slot ready for a live-value panel
				// without a second resolution path if one is ever built.
				'acf'            => array( 'type' => 'object' ),
			),
		)
	);

	// The post's recorded publishing issue, read back by the editor after a
	// save. This is what lets the block editor surface a save-time demotion:
	// the demotion happens in the meta-box follow-up POST, whose response the
	// editor never renders, so a notice left for the next page load would be
	// consumed by that request's own background redirect.
	//
	// Enforcement only: the issue store that this route reads (inc/issues.php)
	// is not loaded while enforcement is locked off, so the route is not registered. The editor's
	// issue watcher treats a missing route as "no issue" -- its fetch rejects and
	// is swallowed (see createIssueWatcher() in assets/js/editor.js) -- so the
	// panel degrades cleanly.
	if ( rendar_pc_enforcement_enabled() ) {
		register_rest_route(
			RENDAR_PC_REST_NS,
			'/issue/(?P<post_id>\\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'rendar_pc_rest_issue',
				'permission_callback' => 'rendar_pc_rest_permission',
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}
}
add_action( 'rest_api_init', 'rendar_pc_register_routes' );

/**
 * Report a post's publishing issue and its current status.
 *
 * Reading a backstop issue here counts as the person having been told, so the
 * one-shot admin notice for the same post is cleared — otherwise it would pop
 * up again, stale, on the next classic admin screen they open.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function rendar_pc_rest_issue( $request ) {
	$post_id = (int) $request['post_id'];

	if ( ! get_post( $post_id ) ) {
		return new WP_REST_Response( array( 'code' => 'rendar_prepublish_checks_no_post' ), 404 );
	}

	$shaped = rendar_pc_issue_for_editor( $post_id );

	if ( $shaped['active'] && RENDAR_PC_ISSUE_SOURCE_BACKSTOP === $shaped['issue']['source'] ) {
		$key    = rendar_pc_notice_transient_key( get_current_user_id() );
		$notice = get_transient( $key );

		if ( is_array( $notice ) && isset( $notice['post_id'] ) && (int) $notice['post_id'] === $post_id ) {
			delete_transient( $key );
		}
	}

	return rest_ensure_response( $shaped );
}

/**
 * Only someone who may edit the post may ask about it.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error
 */
function rendar_pc_rest_permission( $request ) {
	$post_id = (int) $request['post_id'];

	if ( $post_id > 0 ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rendar_prepublish_checks_forbidden',
				__( 'You are not allowed to check this article.', 'rendar-prepublish-checks' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	// A post that has never been saved has no ID to check against, so fall back
	// to the post type's own create capability rather than letting the route
	// answer for anyone who is logged in.
	$post_type = $request['post_type'] ? sanitize_key( $request['post_type'] ) : 'post';
	$object    = get_post_type_object( $post_type );

	if ( ! $object || ! current_user_can( $object->cap->edit_posts ) ) {
		return new WP_Error(
			'rendar_prepublish_checks_forbidden',
			__( 'You are not allowed to check this article.', 'rendar-prepublish-checks' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	return true;
}

/**
 * Evaluate the editor's current state.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function rendar_pc_rest_evaluate( $request ) {
	$post_id   = (int) $request['post_id'];
	$overrides = array();

	foreach (
		array(
			'post_type'      => 'post_type',
			'status'         => 'post_status',
			'content'        => 'post_content',
			'featured_media' => 'featured_media',
			'categories'     => 'categories',
			'tags'           => 'tags',
		) as $param => $key
	) {
		if ( null !== $request[ $param ] ) {
			$overrides[ $key ] = $request[ $param ];
		}
	}

	// The decorative acknowledgments are sent as their own parameter rather
	// than inside a meta blob, because the panel updates them optimiztically
	// before the post is saved and the answer has to reflect that immediately.
	$meta = array();

	if ( is_array( $request['decorative'] ) ) {
		$meta[ RENDAR_PC_META_DECORATIVE ] = $request['decorative'];
	}

	// Same acf-into-meta merge as inc/gate.php's rendar_pc_overrides_from_rest() —
	// rendar_pc_read_field_value() already reads this bucket by field name.
	if ( is_array( $request['acf'] ) ) {
		$meta = array_merge( $meta, $request['acf'] );
	}

	if ( $meta ) {
		$overrides['meta'] = $meta;
	}

	$context = Rendar_PC_Context::build( $post_id, $overrides );
	$report  = rendar_pc_evaluate( $context );

	return rest_ensure_response( rendar_pc_shape_report( $report, $context ) );
}

/**
 * Shape an evaluation report for the editor.
 *
 * `would_block` answers the only question the publish button cares about, and
 * it is computed here from the same report the gate uses rather than inferred
 * in JavaScript from the individual severities.
 *
 * @param array                 $report  Evaluation report.
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_shape_report( array $report, Rendar_PC_Context $context ) {
	// Advisory-only: with enforcement off there is no gate, so the
	// editor must not show a publish block or an override control. The evaluator
	// still computes blocking purely, so the editor-facing verdict is forced
	// honest here -- would_block false, override unavailable, no override log --
	// which is exactly what the panel keys its override/publish-block UI on. The
	// check results themselves are unchanged and still render. See
	// rendar_pc_enforcement_enabled() in the main plugin file.
	$enforcing = rendar_pc_enforcement_enabled();

	return array(
		'in_scope'            => $report['in_scope'],
		'gated'               => $report['gated'],
		'would_block'         => $enforcing ? $report['blocking'] : false,
		'is_publishing'       => $report['is_publishing'],
		'was_published'       => $report['was_published'],
		'predates_checks'     => $report['predates_checks'],
		'has_real_blocks'     => $report['has_real_blocks'],
		'skipped_classic'     => $context->skips_content_checks(),
		'failing_errors'      => $report['failing_errors'],
		'unavailable_errors'  => $report['unavailable_errors'] ?? array(),
		'blocking_ids'        => $report['blocking_ids'] ?? $report['failing_errors'],
		'failing_warnings'    => $report['failing_warnings'],
		'checks'              => $report['checks'],
		'can_override'        => $enforcing && current_user_can( 'manage_options' ),
		'override_log'        => ( $enforcing && $context->post_id ) ? rendar_pc_get_override_log( $context->post_id ) : array(),
		'override_meta_key'   => RENDAR_PC_META_OVERRIDE,
		'decorative_meta_key' => RENDAR_PC_META_DECORATIVE,
	);
}
