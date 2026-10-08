<?php
/**
 * The plugin declares and enforces a minimum WordPress/PHP, derived
 * from the APIs it actually uses. The binding constraint is the editor panel's
 * reliance on wp.editor.PluginDocumentSettingPanel / PluginPrePublishPanel,
 * which moved into @wordpress/editor in WP 6.6 (docs/ENVIRONMENT.md). Below the
 * minimum, boot registers ONLY an environment notice and loads no subsystem.
 */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';

// The constants are the single source of truth and mirror the plugin header.
check( defined( 'RENDAR_PC_MIN_WP' ) && '6.6' === RENDAR_PC_MIN_WP, 'min WP declared (6.6)' );
check( defined( 'RENDAR_PC_MIN_PHP' ) && '7.4' === RENDAR_PC_MIN_PHP, 'min PHP declared (7.4)' );

// Pure version logic, independent of boot.
$GLOBALS['wp_version'] = '6.6';
check( '' === rendar_pc_unsupported_environment(), 'supported WordPress (6.6) reports no shortfall' );

$GLOBALS['wp_version'] = '6.5';
check( '' !== rendar_pc_unsupported_environment(), 'WordPress 6.5 (below 6.6) reports a shortfall' );

$GLOBALS['wp_version'] = '6.0';
check( '' !== rendar_pc_unsupported_environment(), 'WordPress well below 6.6 reports a shortfall' );

unset( $GLOBALS['wp_version'] );
check( '' === rendar_pc_unsupported_environment(), 'an indeterminate WordPress version is not falsely blocked' );

// Boot on a too-old WordPress: notice only, nothing else.
$GLOBALS['wp_version'] = '6.5';
check( ! function_exists( 'rendar_pc_evaluate' ), 'advisory core not loaded before boot' );

$snapshot = hooks_snapshot();
fire_hook( 'plugins_loaded' );

check( ! function_exists( 'rendar_pc_evaluate' ), 'advisory core NOT loaded on an unsupported WordPress' );

$added = array_map(
	function ( $p ) {
		return $p[0] . '|' . ( is_string( $p[1] ) ? $p[1] : '(closure)' );
	},
	hooks_added_since( $snapshot )
);
sort( $added );
check(
	array( 'admin_notices|rendar_pc_environment_notice', 'network_admin_notices|rendar_pc_environment_notice' ) === $added,
	'boot added ONLY the two environment-notice hooks on an unsupported WordPress'
);

echo "\nenvironment: OK\n";
