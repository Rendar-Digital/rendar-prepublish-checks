<?php
/**
 * Enforcement requires the boolean `true`, not mere truthiness.
 *
 * A config typo like `define( 'RENDAR_PC_ENFORCEMENT', 'false' )` is a non-empty
 * string -- truthy -- and must NOT switch enforcement on. If it did, the publish
 * gate and the notifier would come
 * up by accident on a site that only mistyped the flag.
 */
require __DIR__ . '/stubs.php';

// The classic footgun: the STRING 'false', which is truthy.
define( 'RENDAR_PC_ENFORCEMENT', 'false' );
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';

check( ! rendar_pc_enforcement_enabled(), "the string 'false' does not enable enforcement" );

fire_hook( 'plugins_loaded' );

// Advisory core booted, but no enforcement subsystem was required.
check( function_exists( 'rendar_pc_evaluate' ), 'advisory core still booted' );
check( ! function_exists( 'rendar_pc_register_gate' ), 'gate NOT loaded for a truthy-but-not-true flag' );
check( ! function_exists( 'rendar_pc_notify_issue_finalized' ), 'notifier NOT loaded for a truthy-but-not-true flag' );

fire_hook( 'init' );
fire_hook( 'rest_api_init' );

check( ! in_array( RENDAR_PC_META_OVERRIDE, registered_meta_keys(), true ), 'override meta NOT registered' );
check( ! in_array( '/issue/(?P<post_id>\\d+)', registered_routes(), true ), 'issue route NOT registered' );

echo "\nenforcement-strict: OK\n";
