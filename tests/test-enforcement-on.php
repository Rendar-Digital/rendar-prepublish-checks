<?php
/**
 * Safety lock: a boolean opt-in is not yet a usable gate. Until the issue
 * lifecycle and scheduled hold are accepted, no partial gate may register or
 * accept override tokens. This test must change when enforcement is unlocked.
 */
require __DIR__ . '/stubs.php';

define( 'RENDAR_PC_ENFORCEMENT', true );
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
check( ! function_exists( 'rendar_pc_register_gate' ), 'no gate before boot' );
fire_hook( 'plugins_loaded' );
check( ! rendar_pc_enforcement_enabled(), 'true opt-in remains locked' );
check( ! function_exists( 'rendar_pc_register_gate' ), 'no partial gate' );
check( ! function_exists( 'rendar_pc_precheck_future_post' ), 'no partial scheduled recheck' );
check( ! function_exists( 'rendar_pc_issue_write_enter' ), 'no partial issue lifecycle' );
check( ! function_exists( 'rendar_pc_notify_issue_finalized' ), 'no notification' );
check( ! in_array( 'rendar_pc_insert_backstop', hook_callbacks( 'wp_insert_post_data' ), true ), 'no backstop' );
check( ! in_array( 'rendar_pc_record_first_publish', hook_callbacks( 'transition_post_status' ), true ), 'no publish marker' );
fire_hook( 'init' );
fire_hook( 'rest_api_init' );
check( ! in_array( RENDAR_PC_META_OVERRIDE, registered_meta_keys(), true ), 'no override write surface' );
check( ! in_array( '/issue/(?P<post_id>\\d+)', registered_routes(), true ), 'no issue route' );
check( in_array( RENDAR_PC_META_DECORATIVE, registered_meta_keys(), true ), 'advisory meta still registered' );
echo "\nenforcement locked: OK\n";
