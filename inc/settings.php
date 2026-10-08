<?php
/**
 * Stored settings: per-check severity, thresholds, and scope.
 *
 * Severity being a setting rather than a constant is the direct answer to the
 * card's "some may be marked as optional or warnings not an error" — moving a
 * check between advisory and blocking is a dropdown, not a deploy.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

define( 'RENDAR_PC_OPTION', 'rendar_pc_settings' );

/**
 * When the checks were switched on. Articles created before it are advisory.
 */
define( 'RENDAR_PC_WATERMARK_OPTION', 'rendar_pc_activated' );

/**
 * The activation watermark, as a UTC MySQL datetime.
 *
 * @return string Empty when it has not been set yet.
 */
function rendar_pc_watermark() {
	$value = get_option( RENDAR_PC_WATERMARK_OPTION, '' );

	return is_string( $value ) ? $value : '';
}

/**
 * Set the watermark once, the first time the plugin runs.
 *
 * Written here as well as on activation because the activation hook does not
 * fire for a plugin that is already active when its files arrive — which is
 * exactly how this one reaches production. Without the watermark nothing is
 * exempt, so a missing one would silently start gating the whole archive.
 *
 * @return void
 */
function rendar_pc_ensure_watermark() {
	if ( '' === rendar_pc_watermark() ) {
		add_option( RENDAR_PC_WATERMARK_OPTION, current_time( 'mysql', true ), '', false );
	}
}
add_action( 'init', 'rendar_pc_ensure_watermark', 1 );

const RENDAR_PC_SEVERITY_OFF     = 'off';
const RENDAR_PC_SEVERITY_WARNING = 'warning';
const RENDAR_PC_SEVERITY_ERROR   = 'error';

/**
 * Valid severities, in increasing order of consequence.
 *
 * @return string[]
 */
function rendar_pc_severities() {
	return array(
		RENDAR_PC_SEVERITY_OFF,
		RENDAR_PC_SEVERITY_WARNING,
		RENDAR_PC_SEVERITY_ERROR,
	);
}

/**
 * Defaults.
 *
 * NEUTRAL by design. Core ships no site-specific policy value:
 *
 *   - Featured-image width thresholds default to 0 (OFF). The width checks are
 *     generic and threshold-driven; 0 makes each not_applicable. A site sets
 *     the minimum/target it wants as stored settings.
 *   - notify_recipients defaults to EMPTY — the publishing-failure email has no
 *     site recipient until an administrator sets one. notify is an
 *     enforcement subsystem and stays dark while enforcement is locked off.
 *   - severities is EMPTY, so each built-in check's registered (descriptor)
 *     default severity flows through rendar_pc_get_severity() unobstructed. A
 *     pinned stored default would win over the descriptor and stop the
 *     definitions filter from changing a check's severity, which is exactly how
 *     the example fixture applies an error-severity policy.
 *
 * See docs/API.md and tests/fixtures/example-policy/ for a site policy example.
 *
 * @return array
 */
function rendar_pc_default_settings() {
	return array(
		'post_types'           => array( 'post' ),
		'skip_classic_content' => true,
		'min_featured_width'   => 0,
		'ideal_featured_width' => 0,
		// Publishing-failure email. On by default, but with NO recipient: a site
		// sets its own. Enforcement-gated, so inert while enforcement is locked off.
		'notify_enabled'       => true,
		'notify_recipients'    => array(),
		'notify_scheduler'     => true,
		// Empty on purpose: descriptor-level severities win when nothing is pinned.
		'severities'           => array(),
	);
}

/**
 * Read the settings, merged over the defaults.
 *
 * @return array
 */
function rendar_pc_get_settings() {
	$stored   = get_option( RENDAR_PC_OPTION, array() );
	$defaults = rendar_pc_default_settings();

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$settings = array_merge( $defaults, $stored );

	// Severities merge per key, so a check added in a later version inherits its
	// own default instead of vanishing behind a stale stored array.
	$settings['severities'] = array_merge(
		$defaults['severities'],
		isset( $stored['severities'] ) && is_array( $stored['severities'] ) ? $stored['severities'] : array()
	);

	return $settings;
}

/**
 * Read one setting.
 *
 * @param string $key      Setting key.
 * @param mixed  $fallback Value when the key is absent.
 * @return mixed
 */
function rendar_pc_get_setting( $key, $fallback = null ) {
	$settings = rendar_pc_get_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
}

/**
 * Resolve the configured severity for a check.
 *
 * Falls back to the check's own registered default, then to warning — an
 * unrecognized stored value must never silently become blocking.
 *
 * @param string $check_id         Check identifier.
 * @param string $registered_default Severity declared at registration.
 * @return string
 */
function rendar_pc_get_severity( $check_id, $registered_default = RENDAR_PC_SEVERITY_WARNING ) {
	$settings = rendar_pc_get_settings();
	$value    = isset( $settings['severities'][ $check_id ] ) ? $settings['severities'][ $check_id ] : $registered_default;

	if ( ! in_array( $value, rendar_pc_severities(), true ) ) {
		return in_array( $registered_default, rendar_pc_severities(), true )
			? $registered_default
			: RENDAR_PC_SEVERITY_WARNING;
	}

	return $value;
}

/**
 * Post types the checks apply to.
 *
 * @return string[]
 */
function rendar_pc_get_post_types() {
	return rendar_pc_sanitize_post_types( rendar_pc_get_setting( 'post_types', array( 'post' ) ) );
}

/** Public, REST-enabled editor post types eligible for the advisory panel. */
function rendar_pc_eligible_post_types() {
	$eligible = array();
	foreach ( get_post_types( array( 'show_in_rest' => true, 'public' => true ), 'objects' ) as $type ) {
		// WP REST omits registered post meta for types without custom-fields
		// support. A panel that cannot persist its decorative marker is not eligible.
		if ( ! empty( $type->show_in_rest ) && ! empty( $type->public )
			&& post_type_supports( $type->name, 'editor' )
			&& post_type_supports( $type->name, 'custom-fields' ) ) {
			$eligible[ $type->name ] = $type->label;
		}
	}
	return $eligible;
}

/** Intersect with registered eligible types; never trust option or POST values. */
function rendar_pc_sanitize_post_types( $types ) {
	if ( ! is_array( $types ) ) {
		$types = array( 'post' );
	}
	$eligible = rendar_pc_eligible_post_types();
	$clean = array();
	foreach ( $types as $type ) {
		if ( is_string( $type ) && isset( $eligible[ $type ] ) ) {
			$clean[ $type ] = true;
		}
	}
	return array_keys( $clean );
}

/**
 * Sanitize a settings payload coming from the settings screen.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function rendar_pc_sanitize_settings( $input ) {
	$defaults = rendar_pc_default_settings();
	$clean    = $defaults;

	if ( ! is_array( $input ) ) {
		return $clean;
	}

	$clean['skip_classic_content'] = ! empty( $input['skip_classic_content'] );
	$clean['notify_enabled']       = ! empty( $input['notify_enabled'] );
	$clean['notify_scheduler']     = ! empty( $input['notify_scheduler'] );

	// Present but empty is a real choice ("nobody on the list"), so only an
	// absent key falls back to the default.
	if ( array_key_exists( 'notify_recipients', $input ) ) {
		$clean['notify_recipients'] = rendar_pc_sanitize_email_list( $input['notify_recipients'] );
	}

	// Widths are clamped rather than rejected: a nonsensical value should not be
	// able to make the gate unsatisfiable, and 0 disables the comparison.
	foreach ( array( 'min_featured_width', 'ideal_featured_width' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$clean[ $key ] = max( 0, min( 10000, absint( $input[ $key ] ) ) );
		}
	}

	// Missing means a legacy caller; the form sends an empty hidden value when
	// nothing is checked. Forged names never enter the stored scope.
	if ( array_key_exists( 'post_types', $input ) ) {
		$clean['post_types'] = rendar_pc_sanitize_post_types( $input['post_types'] );
	}

	if ( isset( $input['severities'] ) && is_array( $input['severities'] ) ) {
		foreach ( $input['severities'] as $check_id => $severity ) {
			$check_id = sanitize_key( $check_id );
			$severity = is_string( $severity ) ? sanitize_key( $severity ) : '';

			if ( $check_id && in_array( $severity, rendar_pc_severities(), true ) ) {
				$clean['severities'][ $check_id ] = $severity;
			}
		}
	}

	return $clean;
}

/**
 * Parse a list of email addresses: an array, or text separated by commas,
 * semicolons, whitespace or new lines. Invalid entries are dropped, not kept.
 *
 * @param mixed $value Raw value.
 * @return string[]
 */
function rendar_pc_sanitize_email_list( $value ) {
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\s,;]+/', $value );
	}

	if ( ! is_array( $value ) ) {
		return array();
	}

	$clean = array();

	foreach ( $value as $address ) {
		$address = sanitize_email( strtolower( trim( (string) $address ) ) );

		if ( '' !== $address && is_email( $address ) ) {
			$clean[ $address ] = true;
		}
	}

	return array_keys( $clean );
}
