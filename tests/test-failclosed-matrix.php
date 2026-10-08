<?php
/** Dormant enforcement hook contract: no production enforcement boot. */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );
check( ! rendar_pc_enforcement_enabled(), 'production lock remains closed' );
// Load dormant surfaces explicitly; drive their registered hooks, not only helpers.
require dirname( __DIR__ ) . '/inc/gate.php';
require dirname( __DIR__ ) . '/inc/scheduled.php';
function get_post( $id ) { return $GLOBALS['test_post'] ?? null; }
function get_post_thumbnail_id( $id ) { return 0; }
function wp_get_post_terms( $id, $tax, $args ) { return array(); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return (string) $v; }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
function is_object_in_taxonomy( $type, $tax ) { return false; }
function has_blocks( $content ) { return false; }
function get_current_user_id() { return 0; }
class WP_Post {
 public $ID = 41, $post_type = 'post', $post_status = 'draft', $post_content = '', $post_date = '2026-02-01 00:00:00', $post_date_gmt = '2026-02-01 00:00:00', $post_date_gmt_original = '', $post_modified = '2026-02-01 00:00:00', $post_date_gmt_old = '', $post_author = 1;
}
class WP_REST_Request implements ArrayAccess {
 private $data;
 function __construct( $data ) { $this->data = $data; }
 function offsetExists( $k ): bool { return isset( $this->data[$k] ); }
 function offsetGet( $k ): mixed { return $this->data[$k] ?? null; }
 function offsetSet( $k, $v ): void { $this->data[$k] = $v; }
 function offsetUnset( $k ): void { unset( $this->data[$k] ); }
 function get_params() { return $this->data; }
}
$GLOBALS['test_post'] = new WP_Post();
$GLOBALS['options'][ RENDAR_PC_WATERMARK_OPTION ] = '2026-01-01 00:00:00';
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array( 'severities' => array() );
$GLOBALS['test_mode'] = 'unavailable';
$GLOBALS['test_called'] = 0;
add_filter( 'rendar_prepublish_checks_definitions', function ( $checks ) {
 return array( 'probe' => array( 'label' => 'Probe', 'severity' => 'error', 'callback' => function () {
  $GLOBALS['test_called']++;
  return $GLOBALS['test_mode'] === 'malformed' ? array( 'status' => 'bogus' ) : rendar_pc_unavailable( 'Probe cannot run' );
 } ) );
} );
fire_hook( 'init' );
check( in_array( 'rendar_pc_rest_gate', hook_callbacks( 'rest_pre_insert_post' ), true ), 'REST gate hook registered only in test' );
set_error_handler( function ( $n, $message ) { throw new RuntimeException( $message ); } );
foreach ( array( 'publish', 'future', 'draft', 'pending' ) as $status ) {
 $prepared = (object) array( 'ID' => 41, 'post_type' => 'post', 'post_status' => $status );
 $verdict = apply_filters( 'rest_pre_insert_post', $prepared, new WP_REST_Request( array( 'status' => $status ) ) );
 $report = $GLOBALS['rendar_pc_rest_verdict'];
 check( 'unavailable' === $report['checks'][0]['status'] && array() === $report['failing_errors'], "$status unavailable not labeled failed" );
 check( ( $verdict instanceof WP_Error ) === in_array( $status, array( 'publish', 'future' ), true ), "$status REST pre-row gate matrix" );
}
$GLOBALS['test_mode'] = 'malformed';
$report = rendar_pc_evaluate( Rendar_PC_Context::build( 41, array( 'post_status' => 'publish' ) ) );
check( $report['blocking'] && 'unavailable' === $report['checks'][0]['status'], 'malformed result blocks' );
$GLOBALS['test_mode'] = 'unavailable';
$GLOBALS['options'][ RENDAR_PC_OPTION ]['severities'] = array( 'probe' => 'warning', 'lost' => 'error', 'stale' => 'warning' );
$report = rendar_pc_evaluate( Rendar_PC_Context::build( 41, array( 'post_status' => 'publish' ) ) );
check( array( 'lost' ) === $report['blocking_ids'] && 'unavailable' === $report['checks'][1]['status'] && false !== strpos( $report['checks'][1]['message'], 'missing or invalid' ), 'missing error descriptor is named, warning unavailable advisory' );
check( ! in_array( 'stale', array_column( $report['checks'], 'id' ), true ), 'stale warning key not resurrected' );
$token = json_encode( array( 'reason' => 'explicit decision', 'checks' => array( 'lost' ) ) );
$GLOBALS['editable_posts'][41] = true;
$prepared = (object) array( 'ID' => 41, 'post_type' => 'post', 'post_status' => 'publish' );
$allowed = apply_filters( 'rest_pre_insert_post', $prepared, new WP_REST_Request( array( 'status' => 'publish', 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) );
check( $allowed === $prepared && array( 'lost' ) === $GLOBALS['rendar_pc_rest_verdict']['override_covers'], 'admin explicit override covers unavailable ID' );
$GLOBALS['allowed'] = false;
$denied = apply_filters( 'rest_pre_insert_post', $prepared, new WP_REST_Request( array( 'status' => 'publish', 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) );
check( $denied instanceof WP_Error && 'rendar_prepublish_checks_override_forbidden' === $denied->code, 'nonadmin cannot bypass pre-row' );
$GLOBALS['allowed'] = true;
$partial = json_encode( array( 'reason' => 'only probe', 'checks' => array( 'probe' ) ) );
check( apply_filters( 'rest_pre_insert_post', $prepared, new WP_REST_Request( array( 'status' => 'publish', 'meta' => array( RENDAR_PC_META_OVERRIDE => $partial ) ) ) ) instanceof WP_Error, 'override omitting blocking ID cannot bypass' );
// A removed or rejected definition with stored error severity produces the
// same named sentinel. The malformed callback must never run.
$GLOBALS['options'][ RENDAR_PC_OPTION ]['severities']['broken'] = 'error';
add_filter( 'rendar_prepublish_checks_definitions', function ( $checks ) {
 $checks['broken'] = array( 'callback' => 'nonexistent_callback', 'requirements' => array( 'version' => 2 ) );
 return $checks;
} );
$report = rendar_pc_evaluate( Rendar_PC_Context::build( 41, array( 'post_status' => 'future' ) ) );
check( in_array( 'broken', $report['unavailable_errors'], true ) && $report['blocking'], 'invalid definition blocks future as unavailable' );
$GLOBALS['options'][ RENDAR_PC_OPTION ]['severities'] = array( 'probe' => 'off', 'lost' => 'off', 'broken' => 'off' );
$report = rendar_pc_evaluate( Rendar_PC_Context::build( 41, array( 'post_status' => 'publish' ) ) );
check( ! $report['blocking'] && array() === $report['checks'], 'off does not execute or block' );
restore_error_handler();
echo "\nfailclosed hook matrix: OK\n";
