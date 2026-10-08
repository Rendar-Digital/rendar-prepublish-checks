<?php
/**
 * Plugin Name: Rendar Prepublish Checks
 * Description: Article quality checks shown live while an author writes, and enforced when the post is published or scheduled. Authors can always save to pending; publishing is what the gate holds.
 * Version:     0.1.0-dev
 * Author:      Rendar Digital
 * Text Domain: rendar-prepublish-checks
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Tested up to: 7.0.4
 * Update URI: https://updates.rendar.digital/rendar-prepublish-checks
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

define( 'RENDAR_PC_VERSION', '0.1.0-dev' );
define( 'RENDAR_PC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RENDAR_PC_URL', plugin_dir_url( __FILE__ ) );
require_once RENDAR_PC_DIR . 'inc/class-rendar-pc-updater.php';
new Rendar_PC_Updater( __FILE__, RENDAR_PC_VERSION );

/**
 * Minimum environment, derived from the actual APIs this plugin depends on.
 *
 * - WordPress 6.6: the editor panel (assets/js/editor.js) mounts through
 *   `wp.editor.PluginDocumentSettingPanel` and `wp.editor.PluginPrePublishPanel`.
 *   Both components were moved out of `@wordpress/edit-post` into
 *   `@wordpress/editor` in WordPress 6.6; on 6.5 and earlier `wp.editor` does
 *   not export them, so the panel never renders and the live checks have no UI.
 *   This is the binding constraint. `WP_HTML_Tag_Processor` (inc/images.php, the
 *   image-discovery engine both the alt-text and photo-credit checks read from)
 *   first shipped in 6.2 and is comfortably subsumed. The full API audit behind
 *   this minimum is docs/ENVIRONMENT.md.
 * - PHP 7.4: arrow functions and typed property usage across the codebase. 7.4
 *   is also comfortably within WordPress 6.6's own supported range.
 *
 * The version strings are the single source of truth; the plugin header above
 * mirrors them for WordPress' own update-screen gating.
 */
define( 'RENDAR_PC_MIN_WP', '6.6' );
define( 'RENDAR_PC_MIN_PHP', '7.4' );

/**
 * REST namespace. Versioned because the editor script is cached by browsers
 * independently of the PHP, so a breaking response shape needs a new route
 * rather than a redefinition of this one.
 */
define( 'RENDAR_PC_REST_NS', 'rendar-prepublish-checks/v1' );

/**
 * Post meta keys.
 *
 * All three are article-scoped and none of them is block content. That is
 * deliberate and load-bearing: the alternative for the decorative marker was a
 * custom attribute on core/image written into saved markup, which would turn
 * every marked image into an invalid block the day this plugin is deactivated
 * — the failure mode of any plugin-specific attribute in saved markup. Post
 * meta degrades to a harmless orphan row instead.
 */
define( 'RENDAR_PC_META_DECORATIVE', '_rendar_pc_decorative' );
define( 'RENDAR_PC_META_OVERRIDE', '_rendar_pc_override' );
define( 'RENDAR_PC_META_OVERRIDE_LOG', '_rendar_pc_override_log' );
define( 'RENDAR_PC_META_SCHEDULE_OVERRIDE', '_rendar_pc_schedule_override' );
define( 'RENDAR_PC_META_SCHEDULE_FAILURE', '_rendar_pc_schedule_failure' );
define( 'RENDAR_PC_META_FIRST_PUBLISHED', '_rendar_pc_first_published' );
define( 'RENDAR_PC_META_SCHEDULED_BY', '_rendar_pc_scheduled_by' );

/**
 * Publishing past a failing error-severity check is gated on `manage_options`
 * — i.e. administrators. A bespoke capability would buy nothing over the admin
 * check and would be one more grant to keep honest. The override is
 * deliberately not gated on `publish_posts`: the whole point is that the
 * person who can publish is the person being checked.
 */

/**
 * The environment shortfall, if any, as a human-readable reason, or an empty string ''.
 *
 * Only reports a shortfall it can positively determine. PHP_VERSION is always
 * known; the WordPress version is checked only when `$wp_version` is populated,
 * so a non-WordPress harness (the stub tests) is never falsely blocked.
 *
 * @return string Empty when the environment is supported.
 */
function rendar_pc_unsupported_environment() {
	if ( version_compare( PHP_VERSION, RENDAR_PC_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: running PHP version. */
			__( 'Rendar Prepublish Checks needs PHP %1$s or newer. This site runs PHP %2$s.', 'rendar-prepublish-checks' ),
			RENDAR_PC_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

	if ( '' !== $wp_version && version_compare( $wp_version, RENDAR_PC_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: running WordPress version. */
			__( 'Rendar Prepublish Checks needs WordPress %1$s or newer (for the editor panel its live checks render in). This site runs WordPress %2$s.', 'rendar-prepublish-checks' ),
			RENDAR_PC_MIN_WP,
			$wp_version
		);
	}

	return '';
}

/**
 * Admin notice shown when the environment is below the minimum WP/PHP.
 *
 * @return void
 */
function rendar_pc_environment_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$reason = rendar_pc_unsupported_environment();

	if ( '' === $reason ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html( $reason );
	echo '</p></div>';
}

/**
 * Enforcement off switch -- the single gate every enforcement path is behind.
 *
 * Enforcement requires a complete issue lifecycle and an independently tested
 * scheduled hold path. Until both are accepted, the plugin runs advisory-only
 * and this returns false unconditionally, so no configuration can expose a
 * partial publish gate.
 *
 * When enforcement is opened up, it must take the boolean `true` and nothing
 * else: a config typo such as `define( 'RENDAR_PC_ENFORCEMENT', 'false' )` is a
 * non-empty string and must not turn the publish gate and the notifier on by
 * accident.
 *
 * @return bool
 */
function rendar_pc_enforcement_enabled() {
	// Safety lock: do not expose a partial gate before the issue lifecycle and
	// the scheduled hold path are accepted.
	return false;
}

/**
 * Boot the plugin.
 *
 * Deferred to `plugins_loaded` at PHP_INT_MAX so every plugin of every class
 * (must-use, network-active, site-active) has loaded before the subsystems
 * register. Everything the subsystems register (init, rest_api_init, admin_*)
 * fires strictly after it.
 *
 * @return void
 */
function rendar_pc_boot() {
	// Refuse to boot on an environment that cannot run the image checks. This
	// registers ONLY a notice and loads nothing else, so a too-old WordPress or
	// PHP never half-initialises the plugin.
	if ( '' !== rendar_pc_unsupported_environment() ) {
		add_action( 'admin_notices', 'rendar_pc_environment_notice' );
		add_action( 'network_admin_notices', 'rendar_pc_environment_notice' );
		return;
	}

	// Always-on advisory core: the evaluator and its checks, the read-only REST
	// evaluate route, the editor panel and its settings, and the decorative meta.
	require_once RENDAR_PC_DIR . 'inc/settings.php';
	require_once RENDAR_PC_DIR . 'inc/images.php';
	require_once RENDAR_PC_DIR . 'inc/custom-fields.php';
	require_once RENDAR_PC_DIR . 'inc/class-rendar-pc-context.php';
	require_once RENDAR_PC_DIR . 'inc/registry.php';
	require_once RENDAR_PC_DIR . 'inc/checks.php';
	require_once RENDAR_PC_DIR . 'inc/meta.php';
	require_once RENDAR_PC_DIR . 'inc/rest.php';
	require_once RENDAR_PC_DIR . 'inc/assets.php';
	require_once RENDAR_PC_DIR . 'inc/admin-settings.php';

	// Enforcement subsystems stay dormant until rendar_pc_enforcement_enabled()
	// is opened up.
	if ( rendar_pc_enforcement_enabled() ) {
		require_once RENDAR_PC_DIR . 'inc/gate.php';
		require_once RENDAR_PC_DIR . 'inc/scheduled.php';
		require_once RENDAR_PC_DIR . 'inc/issues.php';
		require_once RENDAR_PC_DIR . 'inc/notify.php';
		require_once RENDAR_PC_DIR . 'inc/admin-issues.php';
	}
}
add_action( 'plugins_loaded', 'rendar_pc_boot', PHP_INT_MAX );

/**
 * Activation.
 *
 * The override rides `manage_options`, so there is no capability to grant —
 * only the advisory watermark to set. The watermark is set once and never
 * moved, so re-activating does not re-gate the backlog.
 *
 * @return void
 */
function rendar_pc_activate() {
	// The activation hook fires after `plugins_loaded`, so rendar_pc_boot() has
	// not run this request and the watermark helper is not loaded yet. Pull in
	// just the settings file it lives in.
	require_once RENDAR_PC_DIR . 'inc/settings.php';
	rendar_pc_ensure_watermark();
}
register_activation_hook( __FILE__, 'rendar_pc_activate' );
