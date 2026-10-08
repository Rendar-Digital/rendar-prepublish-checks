<?php
/** Stubbed core cron dispatch: real registered plugin hooks, not a direct clear call. */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
define( 'RENDAR_PC_STATUS_UNAVAILABLE', 'unavailable' );
function rendar_pc_get_post_types() { return array( 'post' ); }
class WP_Post {
	public $ID = 17, $post_type = 'post', $post_status = 'future', $post_date_gmt = '2026-01-01 00:00:00', $post_content = '';
}
class Rendar_PC_Context {
	public static function build( $id, $data ) { return $data; }
}
function rendar_pc_evaluate( $context ) { return $GLOBALS['cron_report']; }
function get_post( $id ) { return $GLOBALS['post']; }
function get_post_status( $id ) { return $GLOBALS['status']; }
function clean_post_cache( $id ) {}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][$id][$key] ); }
function do_action( $hook, ...$args ) { fire_hook( $hook, ...$args ); }
function has_action( $hook, $callback ) { return in_array( $callback, hook_callbacks( $hook ), true ) ? 10 : false; }
class Cron_DB {
	public $posts = 'wp_posts';
	public function prepare( $sql, ...$args ) { return $args; }
	public function get_var( $args ) { return $GLOBALS['status']; }
}
$GLOBALS['wpdb'] = new Cron_DB();
require dirname( __DIR__ ) . '/inc/scheduled.php';
require dirname( __DIR__ ) . '/inc/issues.php';
$GLOBALS['post'] = new WP_Post();
$GLOBALS['status'] = 'future';
$GLOBALS['cron_report'] = array( 'checks' => array( array( 'status' => 'unavailable' ) ), 'failing_errors' => array() );
update_post_meta( 17, RENDAR_PC_META_SCHEDULE_FAILURE, array( 'checks' => array( 'image' ) ) );
function check_and_publish_future_post( $id ) {
	if ( ! empty( $GLOBALS['throw_core_once'] ) ) {
		$GLOBALS['throw_core_once'] = false;
		throw new RuntimeException( 'core dispatch interrupted' );
	}
	if ( 'future' !== $GLOBALS['status'] ) return;
	$GLOBALS['status'] = 'publish';
	$GLOBALS['post']->post_status = 'publish';
	fire_hook( 'transition_post_status', 'publish', 'future', $GLOBALS['post'] );
}
// A different post transitioning during this dispatch must not inherit the marker.
add_action( 'publish_future_post', function ( $id ) {
	if ( ! empty( $GLOBALS['skip_other_post'] ) ) return;
	$other = new WP_Post();
	$other->ID = 18;
	$other->post_status = 'publish';
	update_post_meta( 18, RENDAR_PC_META_SCHEDULE_FAILURE, array( 'checks' => array( 'old' ) ) );
	$GLOBALS['status'] = 'publish';
	rendar_pc_clear_issue_on_publish( 'publish', 'pending', $other );
	check( null === rendar_pc_get_issue( 18 ), 'incomplete cron marker is post-scoped' );
	$GLOBALS['status'] = 'future';
}, 7, 1 );
add_action( 'publish_future_post', 'check_and_publish_future_post', 10, 1 );
fire_hook( 'publish_future_post', 17 );
check( 'publish' === $GLOBALS['status'] && null !== rendar_pc_get_issue( 17 ), 'incomplete verdict survives core cron publish transition' );
check( empty( rendar_pc_issue_state()['incomplete'][17] ), 'incomplete state cleared after dispatch' );
rendar_pc_clear_issue_on_publish( 'publish', 'pending', $GLOBALS['post'] );
check( null === rendar_pc_get_issue( 17 ), 'independent later publish can resolve issue' );
// A thrown callback skips the PHP_INT_MAX leave hook. Recovery must clear
// even an incomplete verdict with no queued intent, without clearing another
// post's unrelated issue. The exception still belongs to the caller.
update_post_meta( 17, RENDAR_PC_META_SCHEDULE_FAILURE, array( 'checks' => array( 'image' ) ) );
$GLOBALS['status'] = 'future';
$GLOBALS['post']->post_status = 'future';
$GLOBALS['throw_core_once'] = true;
$caught = false;
try {
	fire_hook( 'publish_future_post', 17 );
} catch ( RuntimeException $e ) {
	$caught = 'core dispatch interrupted' === $e->getMessage();
}
check( $caught && ! empty( rendar_pc_issue_state()['incomplete'][17] ), 'exception propagates; leave hook was skipped' );
update_post_meta( 18, RENDAR_PC_META_SCHEDULE_FAILURE, array( 'checks' => array( 'unrelated' ) ) );
fire_hook( 'shutdown' );
check( empty( rendar_pc_issue_state()['dispatch'] ) && empty( rendar_pc_issue_state()['incomplete'] ), 'shutdown recovers abandoned dispatch markers' );
check( null !== rendar_pc_get_issue( 18 ), 'recovery does not mutate another post issue' );
$GLOBALS['status'] = 'publish';
$GLOBALS['post']->post_status = 'publish';
rendar_pc_clear_issue_on_publish( 'publish', 'future', $GLOBALS['post'] );
check( null === rendar_pc_get_issue( 17 ), 'later independent publish of same post resolves issue' );
check( null !== rendar_pc_get_issue( 18 ), 'other post still has its issue' );
// Error-level unavailable now belongs to the hold path, not the old
// advisory-only incomplete path. Drive the registered cron hook through core.
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_update_post( $data, $error = false ) {
 $GLOBALS['status'] = $data['post_status'];
 $GLOBALS['post']->post_status = $data['post_status'];
 return $data['ID'];
}
$GLOBALS['status'] = 'future';
$GLOBALS['post']->post_status = 'future';
$GLOBALS['skip_other_post'] = true;
update_post_meta( 17, RENDAR_PC_META_SCHEDULE_OVERRIDE, array(
 'scheduled' => '2025-12-31 00:00:00', 'checks' => array( 'missing' ),
 'fingerprint' => rendar_pc_schedule_override_fingerprint( 17, '2025-12-31 00:00:00', array( 'missing' ) ),
) );
check( array() === rendar_pc_schedule_override_checks( 17, $GLOBALS['post']->post_date_gmt ), 'expired scheduling attempt cannot override missing check' );
$GLOBALS['cron_report'] = array(
 'checks' => array( array( 'id' => 'missing', 'label' => 'missing', 'severity' => 'error', 'status' => 'unavailable', 'message' => 'Configured error check definition is missing or invalid.' ) ),
 'failing_errors' => array(), 'unavailable_errors' => array( 'missing' ), 'blocking_ids' => array( 'missing' ),
);
fire_hook( 'publish_future_post', 17 );
check( 'pending' === $GLOBALS['status'], 'cron holds scheduled row with error-level unavailable check' );
check( null !== rendar_pc_get_issue( 17 ), 'incomplete hold preserves recorded issue' );
check( false === rendar_pc_enforcement_enabled(), 'enforcement remains off' );
echo "\ncron incomplete issue contract passed\n";
