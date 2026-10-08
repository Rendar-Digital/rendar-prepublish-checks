<?php
/** Isolated lifecycle contract: no WordPress mailer, cron runner or live database. */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );
require dirname( __DIR__ ) . '/inc/gate.php';
require dirname( __DIR__ ) . '/inc/scheduled.php';
require dirname( __DIR__ ) . '/inc/issues.php';
require dirname( __DIR__ ) . '/inc/notify.php';
class WP_Post {
	public $ID = 17, $post_type = 'post', $post_status = 'future', $post_date_gmt = '2026-01-01 00:00:00';
}
class Issue_DB {
	public $posts = 'wp_posts', $options = 'wp_options', $status = 'pending', $claims = array(), $fail = false, $inserts = 0, $deletes = 0, $purges = 0, $fail_delete = false;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function esc_like( $value ) { return $value; }
	public function get_var( $query ) { return $this->status; }
	public function query( $query ) {
		list( $sql, $args ) = $query;
		if ( strpos( $sql, 'INSERT INTO' ) !== false ) {
			++$this->inserts;
			if ( $this->fail ) return false;
			if ( isset( $this->claims[$args[0]] ) && (int) $this->claims[$args[0]] > $args[2] ) return 0;
			$was = isset( $this->claims[$args[0]] );
			$this->claims[$args[0]] = $args[1];
			return $was ? 2 : 1;
		}
		if ( strpos( $sql, 'option_name = %s' ) !== false ) {
			++$this->deletes;
			if ( $this->fail_delete ) return false;
			if ( ( $this->claims[$args[0]] ?? null ) === $args[1] ) unset( $this->claims[$args[0]] );
		} else {
			++$this->purges;
		}
		return 0; // bounded purge is deliberately inert in this stub.
	}
}
$GLOBALS['wpdb'] = new Issue_DB();
$GLOBALS['post'] = new WP_Post();
$GLOBALS['editable_posts'] = array( 17 => true );
$GLOBALS['options'][RENDAR_PC_OPTION] = array( 'notify_recipients' => array( 'editor@example.org' ) );
$GLOBALS['sent'] = array();
$GLOBALS['mail_ok'] = false;
function get_post( $id ) { return 17 === (int) $id ? $GLOBALS['post'] : null; }
function get_post_status( $id ) { return $GLOBALS['wpdb']->status; }
function clean_post_cache( $id ) {}
function get_current_user_id() { return 4; }
function get_userdata( $id ) { return false; }
function set_transient( ...$args ) {}
function has_action( $hook, $callback ) { return in_array( $callback, hook_callbacks( $hook ), true ) ? 10 : false; }
function do_action( $hook, ...$args ) { fire_hook( $hook, ...$args ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_meta'][$id][$key] ); }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ++$n; }
function is_email( $email ) { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function get_bloginfo( $key ) { return 'Example'; }
function get_the_title( $id ) { return 'Article'; }
function get_date_from_gmt( $date, $format ) { return $date; }
function wp_timezone_string() { return 'UTC'; }
function admin_url( $path ) { return 'https://example.org/wp-admin/' . $path; }
function home_url() { return 'https://example.org'; }
function wp_specialchars_decode( $s, $flags ) { return html_entity_decode( $s, $flags ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_mail( ...$args ) { $GLOBALS['sent'][] = $args; return $GLOBALS['mail_ok']; }
function get_post_type_object( $type ) { return (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts' ) ); }
class Issue_Request implements ArrayAccess {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function offsetExists( $key ): bool { return isset( $this->data[$key] ); }
	public function offsetGet( $key ): mixed { return $this->data[$key] ?? null; }
	public function offsetSet( $key, $value ): void { $this->data[$key] = $value; }
	public function offsetUnset( $key ): void { unset( $this->data[$key] ); }
}
$report = array( 'checks' => array( array( 'id' => 'image', 'label' => 'Image', 'message' => 'Missing', 'status' => 'fail' ) ) );
$intent = array( 'source' => RENDAR_PC_ISSUE_SOURCE_CRON, 'path' => 'cron-event', 'intended_status' => 'future', 'scheduled' => '2026-01-01 00:00:00', 'report' => $report, 'failing' => array( 'image' ) );
$GLOBALS['events'] = array();
add_action( 'rendar_prepublish_checks_issue_finalized', function ( $id, $record ) { $GLOBALS['events'][] = $record; }, 20, 3 );
rendar_pc_future_dispatch_enter( 17 );
rendar_pc_queue_issue( 17, $intent );
rendar_pc_issue_write_leave( 17 );
check( null === rendar_pc_get_issue( 17 ), 'nested save does not finalize cron-owned issue' );
rendar_pc_finalize_after_future_dispatch( 17 );
check( 1 === count( $GLOBALS['events'] ) && rendar_pc_get_issue( 17 )['held'], 'dispatch finalizes held issue once' );
check( null === rendar_pc_finalize_issue( 17 ), 'finalizer idempotent' );
check( 1 === count( $GLOBALS['sent'] ), 'one attempted email; stub refuses send' );
check( ! $GLOBALS['wpdb']->claims, 'failed mail releases claim' );
$GLOBALS['mail_ok'] = true;
check( rendar_pc_send_failure_email( 17, rendar_pc_get_issue( 17 ) ), 'retry after failed mail' );
check( ! rendar_pc_send_failure_email( 17, rendar_pc_get_issue( 17 ) ), 'duplicate suppressed after success' );
$before = count( $GLOBALS['sent'] );
$inserts = $GLOBALS['wpdb']->inserts;
$GLOBALS['wpdb']->fail = true;
// A fresh fingerprint: the earlier held issue was already claimed in this request.
$fresh = array_merge( rendar_pc_get_issue( 17 ), array( 'checks' => array( 'new-failure' ) ) );
check( ! rendar_pc_send_failure_email( 17, $fresh ), 'failed DB claim refuses mail' );
check( $inserts + 1 === $GLOBALS['wpdb']->inserts && $before === count( $GLOBALS['sent'] ), 'failed DB claim actually queried DB' );
$GLOBALS['wpdb']->fail = false;
$key = RENDAR_PC_NOTIFY_CLAIM_PREFIX . rendar_pc_failure_fingerprint( 17, $fresh );
$GLOBALS['wpdb']->claims[$key] = ( time() - RENDAR_PC_NOTIFY_DEDUPE_SECONDS - 1 ) . ':prior';
check( rendar_pc_send_failure_email( 17, $fresh ), 'expired claim taken over and mailed' );
check( $before + 1 === count( $GLOBALS['sent'] ) && ! rendar_pc_send_failure_email( 17, $fresh ), 'takeover does not double mail' );
$stale = array_merge( $fresh, array( 'checks' => array( 'stale-owner' ) ) );
$stale_key = RENDAR_PC_NOTIFY_CLAIM_PREFIX . rendar_pc_failure_fingerprint( 17, $stale );
check( rendar_pc_claim_failure_notification( 17, rendar_pc_failure_fingerprint( 17, $stale ) ), 'claim stale-owner test' );
$GLOBALS['wpdb']->claims[$stale_key] = time() . ':new-owner';
rendar_pc_release_failure_notification( rendar_pc_failure_fingerprint( 17, $stale ) );
check( time() . ':new-owner' === $GLOBALS['wpdb']->claims[$stale_key], 'stale owner cannot delete replacement claim' );
$retry = array_merge( $fresh, array( 'checks' => array( 'failed-delete' ) ) );
$retry_key = RENDAR_PC_NOTIFY_CLAIM_PREFIX . rendar_pc_failure_fingerprint( 17, $retry );
$GLOBALS['mail_ok'] = false;
$GLOBALS['wpdb']->fail_delete = true;
check( ! rendar_pc_send_failure_email( 17, $retry ), 'failed mail with failed release' );
$inserts = $GLOBALS['wpdb']->inserts;
$sent_after_failure = count( $GLOBALS['sent'] );
check( ! rendar_pc_send_failure_email( 17, $retry ) && $inserts + 1 === $GLOBALS['wpdb']->inserts, 'failed DELETE retains bounded DB suppression' );
check( $sent_after_failure === count( $GLOBALS['sent'] ) && $GLOBALS['wpdb']->purges < 20, 'failed DELETE does not double-mail or unbound purge' );
check( isset( $GLOBALS['wpdb']->claims[$retry_key] ), 'failed DELETE leaves live claim' );
$GLOBALS['wpdb']->fail_delete = false;
$GLOBALS['mail_ok'] = true;
$GLOBALS['wpdb']->status = 'publish';
rendar_pc_queue_issue( 17, $intent );
$reopened = rendar_pc_finalize_issue( 17 );
check( ! $reopened['held'] && 'publish' === $reopened['stored_status'], 'reopen recorded as not held' );
// Save-time backstop: the outermost write, not its nested save, owns finalization.
$state = &rendar_pc_issue_state();
$state['depth'][17] = 2;
$GLOBALS['wpdb']->status = 'pending';
rendar_pc_queue_issue( 17, array_merge( $intent, array( 'source' => RENDAR_PC_ISSUE_SOURCE_BACKSTOP, 'path' => 'save', 'user_id' => 4, 'intended_status' => 'future' ) ) );
rendar_pc_issue_write_leave( 17 );
check( null !== rendar_pc_pending_issue( 17 ), 'nested backstop write waits for outer save' );
rendar_pc_issue_write_leave( 17 );
check( null === rendar_pc_pending_issue( 17 ) && 'backstop' === rendar_pc_get_issue( 17 )['source'], 'outer save records backstop' );
$GLOBALS['wpdb']->status = 'publish';
rendar_pc_clear_issue_on_publish( 'publish', 'pending', $GLOBALS['post'] );
check( null === rendar_pc_get_issue( 17 ), 'successful publish resolves issue' );
$GLOBALS['wpdb']->status = 'pending';
rendar_pc_queue_issue( 17, $intent );
rendar_pc_finalize_issue( 17 );
$GLOBALS['wpdb']->status = 'future';
$GLOBALS['post']->post_status = 'future';
check( ! rendar_pc_issue_for_editor( 17 )['active'], 'future issue not marked active' );
$GLOBALS['wpdb']->status = 'pending';
check( rendar_pc_issue_for_editor( 17 )['active'], 'pending issue visible' );
check( ! rendar_pc_report_is_complete( array( 'checks' => array( array( 'status' => RENDAR_PC_STATUS_UNAVAILABLE ) ) ) ), 'unavailable cannot resolve issue' );
$GLOBALS['rendar_pc_rest_verdict'] = array( 'checks' => array( array( 'status' => RENDAR_PC_STATUS_UNAVAILABLE ) ) );
$GLOBALS['wpdb']->status = 'publish';
rendar_pc_clear_issue_on_publish( 'publish', 'pending', $GLOBALS['post'] );
check( null !== rendar_pc_get_issue( 17 ), 'unavailable REST verdict cannot clear recorded failure' );
$GLOBALS['rendar_pc_rest_verdict'] = null;
check( rendar_pc_report_is_complete( $report ), 'complete report can resolve' );
$request = new Issue_Request( array( 'post_id' => 17 ) );
$GLOBALS['allowed'] = false;
check( true === rendar_pc_rest_permission( $request ), 'editable post allowed without admin' );
$GLOBALS['editable_posts'] = array();
check( rendar_pc_rest_permission( $request ) instanceof WP_Error, 'anonymous and non-editor denied' );
$GLOBALS['allowed'] = true;
check( rendar_pc_rest_permission( $request ) instanceof WP_Error, 'admin cannot read post lacking edit_post' );
$GLOBALS['editable_posts'][17] = true;
check( true === rendar_pc_rest_permission( $request ), 'admin editor allowed' );
check( false === rendar_pc_enforcement_enabled(), 'advisory boot remains locked' );
echo "\nissue lifecycle contract passed\n";
