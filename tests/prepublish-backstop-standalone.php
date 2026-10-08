<?php
/**
 * Standalone assertions for the Pre-Publish wp_insert_post_data backstop.
 *
 * Pins the two defects behind scheduled posts that "did not publish" (they
 * were silently demoted to pending two seconds after being scheduled):
 *
 * 1. The block editor's meta-box compatibility save (`post.php?meta-box-loader=1`)
 *    re-applies the status the REST write just stored. The backstop must not
 *    judge it inside wp_insert_post_data, where the save has not landed — but
 *    it must not EXEMPT it either (2b: a forged follow-up could
 *    strip terms/thumbnail/ACF from a scheduled post unjudged). It is deferred
 *    to a check of the stored post once the whole write has landed.
 * 2. wp_insert_post_data hands the filter SLASHED data. The evaluator must see
 *    unslashed content, while the array returned to core must stay slashed.
 *
 * 3. (2c) A demotion is only a hold once `pending` is read back
 *    from the row. When wp_update_post() leaves the status where it was, the
 *    status is written directly; when even that does not land, the cron path
 *    detaches core's publisher for that one dispatch, and the record and the
 *    action say "not held", never "held".
 *
 * 4. (2d) The hold is re-read after every dispatch it causes. A
 *    status listener that re-saves the post as `future` (or `publish`) from
 *    inside the hold's own transition makes it NOT held: the cron path fails
 *    closed and the record says so.
 *
 * 5. (2e) Every demotion path records and emails from ONE
 *    finalizer, after the outer save (or cron dispatch) is over, from an
 *    uncached read. A listener later in the same save that reopens the post
 *    to `future` or `publish` makes it recorded and emailed NOT held; `held`
 *    has no default.
 *
 * 6. A cron intent is owned by its publish_future_post dispatch. A same-post
 *    save nested inside the dispatch reaching wp_after_insert_post must not
 *    finalize it: hold -> nested save -> reopen is recorded and emailed NOT
 *    held, from the end of the dispatch.
 *
 * Loads the real inc/gate.php, inc/scheduled.php, inc/issues.php and
 * inc/notify.php against the stubs below; wp_mail() is captured.
 *
 * Runs the repository's dormant implementation against stubbed WordPress;
 * this does not exercise the enforcement-off boot switch.
 *
 * Run:  php tests/prepublish-backstop-standalone.php
 */

define( 'ABSPATH', __DIR__ );

// ---------------------------------------------------------------------------
// Minimal WordPress surface.
// ---------------------------------------------------------------------------

$GLOBALS['pcbs'] = array();

function pcbs_reset( array $state ) {
	$GLOBALS['pcbs'] = array_merge(
		array(
			'logged_in'      => true,
			'doing_cron'     => false,
			'db_status'      => array(),
			'blocking'       => true,
			'built'          => array(),
			'transients'     => array(),
			'parked'         => array(),
			'is_admin'       => false,
			'stored_blocking' => false,
			'authorized'     => array(),
			'updates'        => array(),
			'issues'         => array(),
			'actions'        => array(),
			'stored_built'   => 0,
			'db_date'        => array(),
			'update_mode'    => 'ok',
			'direct_fails'   => false,
			'direct_writes'  => array(),
			'transitions'    => array(),
			'meta'           => array(),
			'reopen'         => null,
			'reopened'       => 0,
			'mail'           => array(),
			'new_id'         => 0,
		),
		$state
	);
	if ( function_exists( 'rendar_pc_issue_state' ) ) {
		$issue_state = &rendar_pc_issue_state();
		$issue_state = array(
			'intents'  => array(),
			'depth'    => array(),
			'dispatch' => array(),
		);
	}
	$GLOBALS['pagenow']        = $state['pagenow'] ?? '';
	$_SERVER['REQUEST_METHOD'] = $state['method'] ?? 'GET';
	$_GET                      = $state['get'] ?? array();
	$_POST                     = $state['post'] ?? array();
}

/**
 * The request Gutenberg sends ~2s after a REST save: post.php?meta-box-loader=1.
 */
function pcbs_metabox_request( $post_id, array $extra_post = array(), $nonce = 'valid-mbl' ) {
	return array(
		'is_admin' => true,
		'pagenow'  => 'post.php',
		'method'   => 'POST',
		'get'      => array(
			'post'                  => (string) $post_id,
			'action'                => 'edit',
			'meta-box-loader'       => '1',
			'meta-box-loader-nonce' => $nonce,
		),
		'post'     => array_merge(
			array(
				'action'               => 'editpost',
				'originalaction'       => 'editpost',
				'post_ID'              => (string) $post_id,
				'post_type'            => 'post',
				'original_post_status' => 'future',
			),
			$extra_post
		),
	);
}

/**
 * A classic-editor submit of the same post: post.php, editpost, but no marker.
 */
function pcbs_classic_request( $post_id ) {
	return array(
		'is_admin' => true,
		'pagenow'  => 'post.php',
		'method'   => 'POST',
		'post'     => array(
			'action'     => 'editpost',
			'post_ID'    => (string) $post_id,
			'post_title' => 'Title',
			'content'    => 'x',
		),
	);
}

/**
 * Quick Edit: admin-ajax.php inline-save.
 */
function pcbs_quick_edit_request( $post_id ) {
	return array(
		'is_admin' => true,
		'pagenow'  => 'admin-ajax.php',
		'method'   => 'POST',
		'post'     => array(
			'action'      => 'inline-save',
			'post_ID'     => (string) $post_id,
			'_status'     => 'future',
			'post_title'  => 'Title',
		),
	);
}

function is_admin() {
	return $GLOBALS['pcbs']['is_admin'];
}
function wp_verify_nonce( $nonce, $action ) {
	return 'meta-box-loader' === $action && 'valid-mbl' === $nonce ? 1 : false;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}
$GLOBALS['pcbs_hooks'] = array();
function add_filter( $hook, $cb, $priority = 10 ) {
	$GLOBALS['pcbs_hooks'][ $hook ][ is_string( $cb ) ? $cb : spl_object_hash( (object) $cb ) ] = $priority;
	return true;
}
function add_action( $hook, $cb, $priority = 10 ) {
	return add_filter( $hook, $cb, $priority );
}
function remove_action( $hook, $cb, $priority = 10 ) {
	if ( isset( $GLOBALS['pcbs_hooks'][ $hook ][ $cb ] ) && $GLOBALS['pcbs_hooks'][ $hook ][ $cb ] === $priority ) {
		unset( $GLOBALS['pcbs_hooks'][ $hook ][ $cb ] );
		return true;
	}
	return false;
}
function has_action( $hook, $cb = false ) {
	return isset( $GLOBALS['pcbs_hooks'][ $hook ][ $cb ] ) ? $GLOBALS['pcbs_hooks'][ $hook ][ $cb ] : false;
}
function do_action( $hook, ...$args ) {
	$GLOBALS['pcbs']['actions'][] = array_merge( array( $hook ), $args );
	// The plugin's own actions are dispatched for real, so inc/notify.php
	// sends (into the captured wp_mail()) exactly what the plugin would.
	if ( 0 === strpos( $hook, 'rendar_prepublish_checks_' ) ) {
		$callbacks = $GLOBALS['pcbs_hooks'][ $hook ] ?? array();
		asort( $callbacks );
		foreach ( array_keys( $callbacks ) as $cb ) {
			if ( is_callable( $cb ) ) {
				call_user_func_array( $cb, $args );
			}
		}
	}
}
function apply_filters( $hook, $value ) {
	return $value;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
class WP_Error {
	public $code;
	public function __construct( $code = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
class WP_Post {
	public $ID;
	public $post_type = 'post';
	public $post_status;
	public $post_content  = '';
	public $post_date_gmt = '2030-01-15 15:00:00';
}
function clean_post_cache() {}
function get_post( $id ) {
	if ( ! isset( $GLOBALS['pcbs']['db_status'][ (int) $id ] ) ) {
		return null;
	}
	$post              = new WP_Post();
	$post->ID          = (int) $id;
	$post->post_status = $GLOBALS['pcbs']['db_status'][ (int) $id ];
	if ( isset( $GLOBALS['pcbs']['db_date'][ (int) $id ] ) ) {
		$post->post_date_gmt = $GLOBALS['pcbs']['db_date'][ (int) $id ];
	}
	return $post;
}
/**
 * wp_update_post(): `ok` stores the status; `pinned` behaves like a
 * wp_insert_post_data filter keeping the old status (returns the ID, stores
 * nothing new); `error` returns a WP_Error and stores nothing.
 */
function wp_update_post( $postarr ) {
	$GLOBALS['pcbs']['updates'][] = $postarr;
	if ( 'error' === $GLOBALS['pcbs']['update_mode'] ) {
		return new WP_Error( 'db_update_error' );
	}
	if ( 'ok' === $GLOBALS['pcbs']['update_mode'] ) {
		$GLOBALS['pcbs']['db_status'][ (int) $postarr['ID'] ] = $postarr['post_status'];
		// wp_insert_post() dispatches the status transition before it returns.
		pcbs_status_listener( (int) $postarr['ID'], $postarr['post_status'], 'update' );
	}
	return (int) $postarr['ID'];
}
/**
 * A third-party status listener (transition_post_status / future_to_pending /
 * pending_post) that re-saves a post the moment it becomes pending.
 * `reopen` = { to: status it re-saves, on: 'always' | 'dispatch' (only the
 * transition the direct write dispatches) | 'update-once' (only the first,
 * inside wp_update_post()) | 'save' (only the transition of the outer save
 * that the backstop demoted: pcbs_core_save()) }.
 */
function pcbs_status_listener( $post_id, $new, $via ) {
	$r = $GLOBALS['pcbs']['reopen'];
	if ( ! $r || 'pending' !== $new ) {
		return;
	}
	if ( in_array( $r['on'], array( 'dispatch', 'save' ), true ) && $r['on'] !== $via ) {
		return;
	}
	if ( 'update-once' === $r['on'] && ( 'update' !== $via || $GLOBALS['pcbs']['reopened'] ) ) {
		return;
	}
	$GLOBALS['pcbs']['db_status'][ $post_id ] = $r['to'];
	++$GLOBALS['pcbs']['reopened'];
}
/**
 * $wpdb: an uncached status read, and the direct compare-and-set write.
 */
class PCBS_WPDB {
	public $posts   = 'wp_posts';
	public $options = 'wp_options';
	public $claims  = array();
	/**
	 * The atomic email claim (INSERT ... ON DUPLICATE KEY) and its purge.
	 */
	public function query( $query ) {
		if ( preg_match( '/^INSERT INTO wp_options \(option_name, option_value, autoload\) VALUES \(([^,]+),/', $query, $m ) ) {
			if ( isset( $this->claims[ $m[1] ] ) ) {
				return 0;
			}
			$this->claims[ $m[1] ] = true;
			return 1;
		}
		return 0;
	}
	public function esc_like( $text ) {
		return $text;
	}
	public function prepare( $query, ...$args ) {
		return vsprintf( $query, $args );
	}
	public function get_var( $query ) {
		preg_match( '/ID = (\d+)/', $query, $m );
		return $GLOBALS['pcbs']['db_status'][ (int) $m[1] ] ?? null;
	}
	public function update( $table, $data, $where ) {
		$GLOBALS['pcbs']['direct_writes'][] = array( $data, $where );
		if ( $GLOBALS['pcbs']['direct_fails'] ) {
			return false;
		}
		$id = (int) $where['ID'];
		if ( ( $GLOBALS['pcbs']['db_status'][ $id ] ?? null ) !== $where['post_status'] ) {
			return 0;
		}
		$GLOBALS['pcbs']['db_status'][ $id ] = $data['post_status'];
		return 1;
	}
}
$GLOBALS['wpdb'] = new PCBS_WPDB();
function wp_transition_post_status( $new, $old, $post ) {
	$GLOBALS['pcbs']['transitions'][] = array( (int) $post->ID, "{$old}->{$new}" );
	pcbs_status_listener( (int) $post->ID, $new, 'dispatch' );
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['pcbs']['meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['pcbs']['meta'][ (int) $id ][ $key ] = $value;
	// Every issue record written, in order (the real rendar_pc_record_issue()).
	if ( RENDAR_PC_META_SCHEDULE_FAILURE === $key ) {
		$GLOBALS['pcbs']['issues'][] = array( 'post_id' => (int) $id ) + $value;
	}
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['pcbs']['meta'][ (int) $id ][ $key ] );
	return true;
}
// inc/meta.php: the cumulative, audit-only override log.
function rendar_pc_get_override_log() {
	return $GLOBALS['pcbs']['authorized'] ? array( array( 'checks' => $GLOBALS['pcbs']['authorized'] ) ) : array();
}
const RENDAR_PC_META_SCHEDULE_FAILURE = '_rendar_pc_schedule_failure';
const RENDAR_PC_META_SCHEDULED_BY     = '_rendar_pc_scheduled_by';
const RENDAR_PC_META_OVERRIDE         = '_rendar_pc_override';
const RENDAR_PC_META_SCHEDULE_OVERRIDE = '_rendar_pc_schedule_override';
function current_time() {
	return '2026-09-25 22:00:00';
}
function rendar_pc_append_override_log( $post_id, $entry ) {
	$GLOBALS['pcbs']['log_appended'][] = array( $post_id, $entry );
}
function rendar_pc_normalize_override( $raw ) {
	$raw = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	return is_array( $raw ) && ! empty( $raw['reason'] ) && ! empty( $raw['checks'] ) ? $raw : null;
}
function __( $text ) {
	return $text;
}
function wp_doing_cron() {
	return $GLOBALS['pcbs']['doing_cron'];
}
function is_user_logged_in() {
	return $GLOBALS['pcbs']['logged_in'];
}
function get_current_user_id() {
	return 175;
}
function current_user_can() {
	return false;
}
function get_post_status( $post_id ) {
	return $GLOBALS['pcbs']['db_status'][ (int) $post_id ] ?? false;
}
function stripslashes_from_strings_only( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}

	return stripslashes_from_strings_only( $value );
}
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_slash', $value );
	}

	return is_string( $value ) ? addslashes( $value ) : $value;
}
function set_transient( $key, $value ) {
	$GLOBALS['pcbs']['transients'][ $key ] = $value;
	return true;
}
function get_term_by() {
	return false;
}
class WP_Term {}
class WP_REST_Request {}
const MINUTE_IN_SECONDS = 60;

function rendar_pc_get_post_types() {
	return array( 'post' );
}

class Rendar_PC_Context {
	public $stored = false;

	public static function build( $post_id, array $overrides = array() ) {
		$context = new self();

		// No overrides at all = built from the stored row (the deferred check).
		if ( array() === $overrides ) {
			$context->stored = true;
			++$GLOBALS['pcbs']['stored_built'];
			return $context;
		}

		$GLOBALS['pcbs']['built'][] = array(
			'post_id'   => $post_id,
			'overrides' => $overrides,
		);

		return $context;
	}
}

function rendar_pc_evaluate( $context = null ) {
	$blocking = ( $context && $context->stored ) ? $GLOBALS['pcbs']['stored_blocking'] : $GLOBALS['pcbs']['blocking'];

	return array(
		'blocking'           => $blocking,
		'failing_errors'     => $blocking ? array( 'post-tags', 'featured-image' ) : array(),
		'unavailable_errors' => array(),
		'blocking_ids'       => $blocking ? array( 'post-tags', 'featured-image' ) : array(),
		'checks'             => array(),
	);
}

/**
 * What core does after the backstop returns: the last wp_insert_post_data
 * filter, the write, then wp_after_insert_post. Only runs against 2b+.
 */
function pcbs_land( $post_id ) {
	if ( ! function_exists( 'rendar_pc_deferred_after_insert' ) ) {
		return false;
	}
	rendar_pc_deferred_enter( array(), array( 'ID' => $post_id ) );
	pcbs_issue_enter( $post_id );
	rendar_pc_deferred_after_insert( $post_id );
	pcbs_issue_leave( $post_id );
	return true;
}

/**
 * The issue finalizer's hooks, where core fires them (2e); inert against older files.
 */
function pcbs_issue_enter( $post_id ) {
	if ( function_exists( 'rendar_pc_issue_write_enter' ) ) {
		rendar_pc_issue_write_enter( array(), array( 'ID' => $post_id ) );
	}
}
function pcbs_issue_leave( $post_id ) {
	if ( function_exists( 'rendar_pc_issue_write_leave' ) ) {
		rendar_pc_issue_write_leave( $post_id );
	}
}
/**
 * Start of the publish_future_post dispatch (PHP_INT_MIN).
 */
function pcbs_start_cron_dispatch( $post_id ) {
	if ( function_exists( 'rendar_pc_future_dispatch_enter' ) ) {
		rendar_pc_future_dispatch_enter( $post_id );
	}
}
/**
 * End of the publish_future_post dispatch (PHP_INT_MAX).
 */
function pcbs_end_cron_dispatch( $post_id ) {
	if ( function_exists( 'rendar_pc_finalize_after_future_dispatch' ) ) {
		rendar_pc_finalize_after_future_dispatch( $post_id );
	}
}
/**
 * One cron event: the re-check at 5, then (core at 10 finds the post) the end of the dispatch.
 */
function pcbs_cron( $post_id ) {
	pcbs_start_cron_dispatch( $post_id );
	rendar_pc_precheck_future_post( $post_id );
	pcbs_end_cron_dispatch( $post_id );
}
/**
 * The transition fallback inside wp_publish_post(), then wp_publish_post()'s own wp_after_insert_post.
 */
function pcbs_fallback( $post_id ) {
	rendar_pc_recheck_scheduled( 'publish', 'future', get_post( $post_id ) );
	pcbs_issue_leave( $post_id );
}
/**
 * What core's wp_insert_post() does around the backstop: the backstop filter
 * (99), the last filters (PHP_INT_MAX: counts the write open), the row write,
 * the status transition — the new-post binding at PHP_INT_MIN (or, in files
 * before 2e, the immediate record there), then a third-party listener that may
 * reopen the post — and finally wp_after_insert_post (stored check 999,
 * finalizer PHP_INT_MAX). $id 0 inserts a new post as $GLOBALS['pcbs']['new_id'].
 */
function pcbs_core_save( $id, $target, $before_leave = null ) {
	global $content;
	$old = $id ? $GLOBALS['pcbs']['db_status'][ $id ] : 'new';
	list( $data, $postarr ) = pcbs_write( $id, $target, $content );
	$data = rendar_pc_insert_backstop( $data, $postarr );
	rendar_pc_deferred_enter( $data, $postarr );
	pcbs_issue_enter( $id );
	$real = $id ? $id : $GLOBALS['pcbs']['new_id'];
	$GLOBALS['pcbs']['db_status'][ $real ] = $data['post_status'];
	$post = get_post( $real );
	if ( function_exists( 'rendar_pc_issue_bind_new_post' ) ) {
		rendar_pc_issue_bind_new_post( $data['post_status'], $old, $post );
	} elseif ( function_exists( 'rendar_pc_consume_pending_demotion' ) ) {
		rendar_pc_consume_pending_demotion( $data['post_status'], $old, $post );
	}
	pcbs_status_listener( $real, $data['post_status'], 'save' );
	if ( $before_leave ) {
		$before_leave( $real );
	}
	rendar_pc_deferred_after_insert( $real );
	pcbs_issue_leave( $real );
	return $data;
}

// inc/notify.php's WordPress surface.
function rendar_pc_get_settings() {
	return array(
		'notify_enabled'    => true,
		'notify_recipients' => array( 'editor@example.com' ),
		'notify_scheduler'  => false,
	);
}
function get_userdata() {
	return false;
}
function is_email( $address ) {
	return (bool) filter_var( $address, FILTER_VALIDATE_EMAIL );
}
function get_bloginfo() {
	return 'Example Site';
}
function wp_specialchars_decode( $text ) {
	return $text;
}
function get_the_title( $id ) {
	return 'Post ' . $id;
}
function get_option( $name ) {
	return 'date_format' === $name ? 'Y-m-d' : ( 'time_format' === $name ? 'H:i' : false );
}
function get_date_from_gmt( $date ) {
	return $date;
}
function wp_timezone_string() {
	return 'UTC';
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function home_url() {
	return 'https://example.test';
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
function wp_generate_uuid4() {
	return 'stub-notification-owner';
}
function wp_mail( $to, $subject, $message ) {
	$GLOBALS['pcbs']['mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
	);
	return true;
}

function rendar_pc_failing_messages() {
	return array( 'Photo credit: 4 images are not in the media library, so their credits could not be checked.' );
}

$pcbs_src = dirname( __DIR__ ) . '/inc';
require $pcbs_src . '/gate.php';
require $pcbs_src . '/scheduled.php';
require $pcbs_src . '/issues.php';
require $pcbs_src . '/notify.php';

// Core's scheduled publisher, where core registers it.
add_action( 'publish_future_post', 'check_and_publish_future_post', 10 );

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------

$failures = 0;
$passes   = 0;

function pcbs_assert( $condition, $label ) {
	global $failures, $passes;

	if ( $condition ) {
		++$passes;
		echo "  ok   {$label}\n";
	} else {
		++$failures;
		echo "  FAIL {$label}\n";
	}
}

// Assert the dormant hooks are registered in the order the scenarios emulate.
pcbs_assert( 99 === has_action( 'wp_insert_post_data', 'rendar_pc_insert_backstop' ) && PHP_INT_MAX === has_action( 'wp_insert_post_data', 'rendar_pc_deferred_enter' ), 'backstop runs before the outer-write tracker' );
pcbs_assert( 5 === has_action( 'publish_future_post', 'rendar_pc_precheck_future_post' ) && 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'cron precheck runs before the core publisher' );
pcbs_assert( PHP_INT_MIN === has_action( 'publish_future_post', 'rendar_pc_future_dispatch_enter' ) && PHP_INT_MAX === has_action( 'publish_future_post', 'rendar_pc_finalize_after_future_dispatch' ), 'cron intent brackets the entire dispatch' );
pcbs_assert( 5 === has_action( 'transition_post_status', 'rendar_pc_recheck_scheduled' ) && 50 === has_action( 'transition_post_status', 'rendar_pc_expire_schedule_override' ), 'publish fallback precedes attempt expiry' );

// Saved content from the affected class of post: a third-party gallery block
// whose image IDs live only in block-comment JSON.
$content = '<!-- wp:example/gallery {"images":[{"id":9105421,"url":"https://example.test/a.jpg","alt":"A sample photo"}],"defaultCaption":"Sample Photographer"} --><div class="wp-block-example-gallery"><img src="https://example.test/a.jpg" alt="A sample photo"/></div><!-- /wp:example/gallery -->';

/**
 * The arrays wp_update_post() -> wp_insert_post() hand the filter: slashed.
 */
function pcbs_write( $id, $status, $content ) {
	$postarr = wp_slash(
		array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_status'   => $status,
			'post_content'  => $content,
			'post_category' => array( 12, 34 ),
		)
	);
	$data    = array(
		'post_type'    => 'post',
		'post_status'  => $status,
		'post_content' => $postarr['post_content'],
	);

	return array( $data, $postarr );
}

echo "1. Genuine meta-box follow-up of a just-scheduled gallery post (DB future -> future): judged on the stored row, stays scheduled\n";
pcbs_reset( array_merge( pcbs_metabox_request( 9105698 ), array( 'db_status' => array( 9105698 => 'future' ) ) ) );
list( $data, $postarr ) = pcbs_write( 9105698, 'future', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'future' === $result['post_status'], 'status stays future inside wp_insert_post_data (regression: previously demoted to pending)' );
pcbs_assert( array() === $GLOBALS['pcbs']['built'], 'not judged on the request: its values have not landed yet' );
pcbs_assert( $result === $data, 'data returned to core untouched' );
pcbs_assert( pcbs_land( 9105698 ) && 1 === $GLOBALS['pcbs']['stored_built'], '2b: judged once, from the stored row, after the write landed' );
pcbs_assert( array() === $GLOBALS['pcbs']['updates'] && array() === $GLOBALS['pcbs']['issues'], 'passing stored state: no demotion, no issue' );
pcbs_assert( array() === $GLOBALS['pcbs']['transients'], 'no "not published" notice left for the editor' );

echo "1a. FORGED meta-box follow-up that strips tags / featured image from a scheduled post: demoted after it lands\n";
pcbs_reset( array_merge( pcbs_metabox_request( 9105700, array( 'tax_input' => array( 'post_tag' => '' ), '_thumbnail_id' => '-1' ) ), array( 'db_status' => array( 9105700 => 'future' ), 'stored_blocking' => true ) ) );
list( $data, $postarr ) = pcbs_write( 9105700, 'future', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'future' === $result['post_status'], 'the insert filter leaves it (the stripped values are not stored yet)' );
pcbs_assert( pcbs_land( 9105700 ), '2b: deferred check present' );
pcbs_assert( 'pending' === ( $GLOBALS['pcbs']['db_status'][9105700] ?? '' ) && 1 === count( $GLOBALS['pcbs']['updates'] ), 'stored state fails: moved to pending (an earlier revision exempted this request)' );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 'backstop' === ( $issue['source'] ?? '' ) && 'future' === ( $issue['intended_status'] ?? '' ) && 175 === ( $issue['user_id'] ?? 0 ) && array( 'post-tags', 'featured-image' ) === ( $issue['checks'] ?? null ), 'recorded: source backstop, intended future, acting user, failing checks' );
$fired = array_values( array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_backstop_demoted' === $a[0]; } ) );
pcbs_assert( 1 === count( $fired ) && 'future' === $fired[0][4], 'notify hook fired once, intended status future' );
pcbs_assert( isset( $GLOBALS['pcbs']['transients']['rendar_pc_notice_175'] ), 'notice for the acting user' );

echo "1b. Override (2c): only the override bound to the CURRENT scheduling attempt is honoured\n";
$T = '2030-01-15 15:00:00';
/**
 * A binding as rendar_pc_bind_schedule_override() writes it.
 */
function pcbs_binding( $id, $scheduled, array $checks ) {
	sort( $checks );
	$fp = function_exists( 'rendar_pc_schedule_override_fingerprint' )
		? rendar_pc_schedule_override_fingerprint( $id, $scheduled, $checks )
		: md5( implode( '|', array( $id, $scheduled, implode( ',', $checks ) ) ) );
	return array( RENDAR_PC_META_SCHEDULE_OVERRIDE => array( 'scheduled' => $scheduled, 'checks' => $checks, 'fingerprint' => $fp, 'time' => 'x', 'user_id' => 1 ) );
}
function pcbs_followup_with( $id, array $state ) {
	global $content;
	pcbs_reset( array_merge( pcbs_metabox_request( $id ), array( 'db_status' => array( $id => 'future' ), 'stored_blocking' => true ), $state ) );
	list( $data, $postarr ) = pcbs_write( $id, 'future', $content );
	rendar_pc_insert_backstop( $data, $postarr );
	pcbs_land( $id );
}
pcbs_followup_with( 9105701, array( 'meta' => array( 9105701 => pcbs_binding( 9105701, $T, array( 'post-tags', 'featured-image' ) ) ) ) );
pcbs_assert( 'future' === $GLOBALS['pcbs']['db_status'][9105701] && array() === $GLOBALS['pcbs']['issues'], 'current-attempt override (this date, these checks) is honoured: stays future' );
pcbs_followup_with( 9105701, array( 'authorized' => array( 'post-tags', 'featured-image' ) ) );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][9105701] && array( 'post-tags', 'featured-image' ) === ( $GLOBALS['pcbs']['issues'][0]['checks'] ?? null ), 'a stale audit-log entry covering both checks is NOT honoured: demoted' );
pcbs_followup_with( 9105701, array( 'db_date' => array( 9105701 => '2030-02-01 09:00:00' ), 'meta' => array( 9105701 => pcbs_binding( 9105701, $T, array( 'post-tags', 'featured-image' ) ) ) ) );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][9105701], 'rescheduled (post_date_gmt no longer the bound date): override invalid, demoted' );
pcbs_followup_with( 9105701, array( 'meta' => array( 9105701 => pcbs_binding( 9105701, $T, array( 'post-tags' ) ) ) ) );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][9105701] && array( 'featured-image' ) === ( $GLOBALS['pcbs']['issues'][0]['checks'] ?? null ), 'a new failing check the override does not cover is still demoted, and is the only reason given' );
$tampered = pcbs_binding( 9105701, $T, array( 'post-tags' ) );
$tampered[ RENDAR_PC_META_SCHEDULE_OVERRIDE ]['checks'] = array( 'featured-image', 'post-tags' );
pcbs_followup_with( 9105701, array( 'meta' => array( 9105701 => $tampered ) ) );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][9105701], 'a binding whose checks no longer match its fingerprint authorizes nothing' );

echo "1b-cron. Same rule at publish time (rendar_pc_scheduled_verdict)\n";
$cronpost = function ( $state ) use ( $T ) {
	pcbs_reset( array_merge( array( 'db_status' => array( 88 => 'future' ), 'db_date' => array( 88 => $T ) ), $state ) );
	return rendar_pc_scheduled_verdict( get_post( 88 ) );
};
pcbs_assert( null === $cronpost( array( 'meta' => array( 88 => pcbs_binding( 88, $T, array( 'post-tags', 'featured-image' ) ) ) ) ), 'current-attempt override honoured at cron time' );
$v = $cronpost( array( 'authorized' => array( 'post-tags', 'featured-image' ) ) );
pcbs_assert( array( 'post-tags', 'featured-image' ) === ( $v['unresolved'] ?? null ), 'stale log entry not honoured at cron time' );
$v = $cronpost( array( 'meta' => array( 88 => pcbs_binding( 88, '2030-01-14 15:00:00', array( 'post-tags', 'featured-image' ) ) ) ) );
pcbs_assert( array( 'post-tags', 'featured-image' ) === ( $v['unresolved'] ?? null ), 'binding for an earlier date not honoured at cron time' );
$v = $cronpost( array( 'meta' => array( 88 => pcbs_binding( 88, $T, array( 'post-tags' ) ) ) ) );
pcbs_assert( array( 'featured-image' ) === ( $v['unresolved'] ?? null ), 'uncovered new failure unresolved at cron time' );

echo "1b-bind. The REST save that consumes an override binds it to that attempt; any other REST save ends it\n";
if ( function_exists( 'rendar_pc_bind_schedule_override' ) ) {
	$saved = function ( $status, $date, $covers, $token = true ) {
		pcbs_reset( array( 'meta' => array( 89 => array( RENDAR_PC_META_OVERRIDE => $token ? '{"reason":"r","checks":["post-tags"]}' : '' ) + pcbs_binding( 89, '2030-01-01 00:00:00', array( 'old-check' ) ) ) ) );
		$GLOBALS['rendar_pc_rest_verdict'] = array( 'override_covers' => $covers, 'blocking' => false );
		$p = new WP_Post();
		$p->ID = 89;
		$p->post_status = $status;
		$p->post_date_gmt = $date;
		rendar_pc_after_rest_insert( $p, null, false );
		$GLOBALS['rendar_pc_rest_verdict'] = null;
		return $GLOBALS['pcbs']['meta'][89][ RENDAR_PC_META_SCHEDULE_OVERRIDE ] ?? null;
	};
	$b = $saved( 'future', $T, array( 'post-tags' ) );
	pcbs_assert( is_array( $b ) && $T === $b['scheduled'] && array( 'post-tags' ) === $b['checks'] && array( 'post-tags' ) === rendar_pc_schedule_override_checks( 89, $T ), 'schedule with a covering override: bound to its date and checks (old binding replaced)' );
	pcbs_assert( 1 === count( $GLOBALS['pcbs']['log_appended'] ?? array() ), 'and still written to the audit log' );
	pcbs_assert( null === $saved( 'future', $T, array(), false ), 'REST save of the scheduled post that passes with no override: previous binding dropped' );
	pcbs_assert( null === $saved( 'publish', $T, array( 'post-tags' ) ), 'override consumed by an immediate publish: nothing bound' );
	pcbs_reset( array( 'meta' => array( 89 => pcbs_binding( 89, $T, array( 'post-tags' ) ) ) ) );
	$p = new WP_Post();
	$p->ID = 89;
	rendar_pc_expire_schedule_override( 'future', 'future', $p );
	pcbs_assert( isset( $GLOBALS['pcbs']['meta'][89][ RENDAR_PC_META_SCHEDULE_OVERRIDE ] ), 'future -> future (the meta-box follow-up) keeps it' );
	rendar_pc_expire_schedule_override( 'pending', 'future', $p );
	pcbs_assert( ! isset( $GLOBALS['pcbs']['meta'][89][ RENDAR_PC_META_SCHEDULE_OVERRIDE ] ), 'leaving future ends the attempt: binding deleted' );
} else {
	pcbs_assert( false, 'no scheduling-attempt binding in this build' );
}

echo "1c. A same-post write nested inside the follow-up: judged once, after the OUTERMOST write\n";
pcbs_reset( array_merge( pcbs_metabox_request( 9105702 ), array( 'db_status' => array( 9105702 => 'future' ), 'stored_blocking' => true ) ) );
list( $data, $postarr ) = pcbs_write( 9105702, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
if ( function_exists( 'rendar_pc_deferred_enter' ) ) {
	rendar_pc_deferred_enter( array(), array( 'ID' => 9105702 ) ); // outer
	rendar_pc_deferred_enter( array(), array( 'ID' => 9105702 ) ); // nested, from a save_post callback
	rendar_pc_deferred_after_insert( 9105702 );                      // nested lands
	pcbs_assert( 0 === $GLOBALS['pcbs']['stored_built'] && 'future' === $GLOBALS['pcbs']['db_status'][9105702], 'not judged while the outer save (ACF on save_post) is still running' );
	rendar_pc_deferred_after_insert( 9105702 );                      // outer lands
	pcbs_assert( 1 === $GLOBALS['pcbs']['stored_built'] && 'pending' === $GLOBALS['pcbs']['db_status'][9105702], 'judged once when the outer write finishes' );
	rendar_pc_deferred_after_insert( 9105702 );
	pcbs_assert( 1 === $GLOBALS['pcbs']['stored_built'], 'the demotion\'s own nested write does not re-trigger it' );
} else {
	pcbs_assert( false, 'no deferred check in this build' );
}

echo "1d. Shutdown net: a deferred check whose write never reached wp_after_insert_post still runs\n";
pcbs_reset( array_merge( pcbs_metabox_request( 9105703 ), array( 'db_status' => array( 9105703 => 'future' ), 'stored_blocking' => true ) ) );
list( $data, $postarr ) = pcbs_write( 9105703, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
if ( function_exists( 'rendar_pc_run_leftover_stored_checks' ) ) {
	rendar_pc_run_leftover_stored_checks();
	pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][9105703], 'judged at shutdown' );
	rendar_pc_run_leftover_stored_checks();
	pcbs_assert( 1 === count( $GLOBALS['pcbs']['updates'] ), 'and only once' );
} else {
	pcbs_assert( false, 'no shutdown net in this build' );
}

echo "2. A published post's meta-box save (DB publish -> publish): not judged, never unpublished\n";
pcbs_reset( array_merge( pcbs_metabox_request( 9105361 ), array( 'db_status' => array( 9105361 => 'publish' ), 'stored_blocking' => true ) ) );
list( $data, $postarr ) = pcbs_write( 9105361, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'publish' === $result['post_status'], 'status stays publish' );
pcbs_assert( array() === $GLOBALS['pcbs']['built'], 'no evaluation on the request' );
pcbs_land( 9105361 );
pcbs_assert( 0 === $GLOBALS['pcbs']['stored_built'] && array() === $GLOBALS['pcbs']['updates'], 'the stored check only ever judges a post still future' );

echo "2a. Quick Edit of a scheduled post with failing content (DB future -> future): demoted\n";
pcbs_reset( array_merge( pcbs_quick_edit_request( 81 ), array( 'db_status' => array( 81 => 'future' ) ) ) );
list( $data, $postarr ) = pcbs_write( 81, 'future', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['built'] ), 'evaluated (1.5.3 skipped it)' );
pcbs_assert( 'pending' === $result['post_status'], 'demoted to pending' );

echo "2b. Classic-editor save of a scheduled post with failing content (DB future -> future): demoted\n";
pcbs_reset( array_merge( pcbs_classic_request( 82 ), array( 'db_status' => array( 82 => 'future' ) ) ) );
list( $data, $postarr ) = pcbs_write( 82, 'future', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'pending' === $result['post_status'], 'demoted to pending' );

echo "2c. wp_update_post() by a logged-in user outside any admin screen (DB future -> future): demoted\n";
pcbs_reset( array( 'db_status' => array( 83 => 'future' ) ) );
list( $data, $postarr ) = pcbs_write( 83, 'future', $content );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'demoted to pending' );

echo "2d. Each meta-box condition is load-bearing\n";
pcbs_reset( array_merge( pcbs_metabox_request( 84, array(), 'forged' ), array( 'db_status' => array( 84 => 'future' ) ) ) );
list( $data, $postarr ) = pcbs_write( 84, 'future', $content );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'marker with an invalid meta-box-loader nonce: adjudicated' );
pcbs_reset( array_merge( pcbs_metabox_request( 84, array( 'content' => 'rewritten' ) ), array( 'db_status' => array( 84 => 'future' ) ) ) );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'marker on a POST carrying editor content: adjudicated' );
pcbs_reset( array_merge( pcbs_metabox_request( 999 ), array( 'db_status' => array( 84 => 'future' ) ) ) );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'nested write to a different post inside the follow-up: adjudicated' );
$req = pcbs_metabox_request( 84 );
$req['method'] = 'GET';
pcbs_reset( array_merge( $req, array( 'db_status' => array( 84 => 'future' ) ) ) );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'not a POST: adjudicated' );
$req = pcbs_metabox_request( 84 );
$req['pagenow'] = 'admin-ajax.php';
pcbs_reset( array_merge( $req, array( 'db_status' => array( 84 => 'future' ) ) ) );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'not post.php: adjudicated' );
$req = pcbs_metabox_request( 84 );
unset( $req['get']['meta-box-loader'] );
pcbs_reset( array_merge( $req, array( 'db_status' => array( 84 => 'future' ) ) ) );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'no marker flag: adjudicated' );

echo "2e. Meta-box follow-up whose status CHANGES (DB future -> publish: the minute passed): adjudicated\n";
pcbs_reset( array_merge( pcbs_metabox_request( 85 ), array( 'db_status' => array( 85 => 'future' ) ) ) );
list( $data, $postarr ) = pcbs_write( 85, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['built'] ), 'evaluated' );
pcbs_assert( 'pending' === $result['post_status'], 'demoted when it fails' );

echo "3. Quick Edit publish of a pending post that genuinely fails (DB pending -> publish)\n";
pcbs_reset( array( 'db_status' => array( 77 => 'pending' ) ) );
list( $data, $postarr ) = pcbs_write( 77, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'pending' === $result['post_status'], 'demoted to pending: the backstop still guards a real status change' );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['built'] ), 'evaluated exactly once' );
pcbs_assert( $result['post_content'] === $data['post_content'], 'content returned to core is still the slashed original (core unslashes it next)' );
if ( function_exists( 'rendar_pc_blocking_checks' ) ) {
	$parked = function_exists( 'rendar_pc_pending_issue' ) ? rendar_pc_pending_issue( 77 ) : null;
	pcbs_assert( is_array( $parked ) && 'publish' === $parked['intended_status'] && array( 'post-tags', 'featured-image' ) === $parked['failing'] && 175 === $parked['user_id'] && 'save' === $parked['path'], '2e: demotion queued for the finalizer (intended status, failing checks, user, path)' );
	pcbs_assert( array() === $GLOBALS['pcbs']['issues'], '2e: nothing recorded while the save is still in flight' );
}

echo "4. The evaluator reads UNSLASHED content\n";
$seen = $GLOBALS['pcbs']['built'][0]['overrides']['post_content'] ?? null;
pcbs_assert( $content === $seen, 'post_content passed to the context builder equals the stored content' );
pcbs_assert( null !== $seen && false === strpos( $seen, '\\"' ), 'no backslash-escaped quotes reach parse_blocks()' );
pcbs_assert( null !== $seen && is_array( json_decode( substr( $seen, strpos( $seen, '{' ), strpos( $seen, ' -->' ) - strpos( $seen, '{' ) ), true ) ), 'the gallery block-comment JSON is decodable, so image IDs survive' );
pcbs_assert( array( 12, 34 ) === ( $GLOBALS['pcbs']['built'][0]['overrides']['categories'] ?? null ), 'categories still read from the post array' );

echo "5. Quick Edit publish of a pending post that passes\n";
pcbs_reset( array( 'db_status' => array( 78 => 'pending' ), 'blocking' => false, 'stored_blocking' => true ) );
list( $data, $postarr ) = pcbs_write( 78, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'publish' === $result['post_status'], 'publishes' );
pcbs_assert( array() === $GLOBALS['pcbs']['transients'], 'no notice' );
pcbs_assert( null === ( function_exists( 'rendar_pc_pending_issue' ) ? rendar_pc_pending_issue( 78 ) : null ), 'nothing queued for a passing publish' );
$GLOBALS['pcbs']['db_status'][78] = 'publish';
pcbs_land( 78 );
pcbs_assert( 0 === $GLOBALS['pcbs']['stored_built'], 'a publish is not re-judged after the fact (it has already been announced)' );

echo "5a. Classic/Quick Edit schedule that passes on the request but whose stored result fails (ACF written on save_post)\n";
pcbs_reset( array_merge( pcbs_quick_edit_request( 87 ), array( 'db_status' => array( 87 => 'pending' ), 'blocking' => false, 'stored_blocking' => true ) ) );
list( $data, $postarr ) = pcbs_write( 87, 'future', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 'future' === $result['post_status'] && 1 === count( $GLOBALS['pcbs']['built'] ), 'synchronous check unchanged: judged on the request, passes' );
$GLOBALS['pcbs']['db_status'][87] = 'future';
pcbs_assert( pcbs_land( 87 ) && 'pending' === $GLOBALS['pcbs']['db_status'][87], '2b: the stored result is judged too, and fails' );

echo "6. A scheduled post moved into the past (DB future -> publish) is still a status change\n";
pcbs_reset( array( 'db_status' => array( 79 => 'future' ) ) );
list( $data, $postarr ) = pcbs_write( 79, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['built'] ), 'evaluated' );
pcbs_assert( 'pending' === $result['post_status'], 'and demoted when it fails' );

echo "7. A brand-new post (no ID yet) going straight to publish\n";
pcbs_reset( array() );
list( $data, $postarr ) = pcbs_write( 0, 'publish', $content );
$result = rendar_pc_insert_backstop( $data, $postarr );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['built'] ), 'evaluated' );
pcbs_assert( 'pending' === $result['post_status'], 'demoted when it fails' );

echo "8. Existing exemptions unchanged (REST, cron, anonymous, pending)\n";
pcbs_reset( array( 'db_status' => array( 80 => 'pending' ), 'doing_cron' => true ) );
list( $data, $postarr ) = pcbs_write( 80, 'publish', $content );
pcbs_assert( 'publish' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'cron write is never demoted' );
pcbs_reset( array( 'db_status' => array( 80 => 'pending' ), 'logged_in' => false ) );
pcbs_assert( 'publish' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'unauthenticated write is never demoted' );
pcbs_reset( array( 'db_status' => array( 80 => 'draft' ) ) );
list( $data, $postarr ) = pcbs_write( 80, 'pending', $content );
pcbs_assert( 'pending' === rendar_pc_insert_backstop( $data, $postarr )['post_status'] && array() === $GLOBALS['pcbs']['built'], 'a pending save is never evaluated' );
pcbs_reset( array( 'db_status' => array( 80 => 'pending' ) ) );
$GLOBALS['rendar_pc_rest_verdict'] = array( 'blocking' => false );
list( $data, $postarr ) = pcbs_write( 80, 'publish', $content );
pcbs_assert( 'publish' === rendar_pc_insert_backstop( $data, $postarr )['post_status'], 'a write the REST gate already adjudicated in this request is left alone' );
pcbs_reset( array( 'db_status' => array( 86 => 'future' ) ) );
list( $data, $postarr ) = pcbs_write( 86, 'future', $content );
pcbs_assert( 'future' === rendar_pc_insert_backstop( $data, $postarr )['post_status'] && array() === $GLOBALS['pcbs']['built'], 'REST future -> future after the gate adjudicated it: untouched, not re-evaluated' );
$GLOBALS['rendar_pc_rest_verdict'] = null;

echo "9. Cron re-check, failing post, wp_update_post() works: held, and only then recorded as held\n";
$past = gmdate( 'Y-m-d H:i:s', time() - 120 );
pcbs_reset( array( 'db_status' => array( 90 => 'future' ), 'db_date' => array( 90 => $past ), 'doing_cron' => true ) );
pcbs_cron( 90 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][90], 'stored as pending' );
pcbs_assert( true === ( $issue['held'] ?? null ) && 'pending' === ( $issue['stored_status'] ?? null ) && 'cron-event' === ( $issue['path'] ?? null ), '2c: record says held, stored status pending, cron-event path' );
pcbs_assert( array() === $GLOBALS['pcbs']['direct_writes'], 'no direct write needed' );
pcbs_assert( 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'core publisher left attached (it will find a pending post and return)' );
pcbs_assert( function_exists( 'rendar_pc_was_reverted' ) && rendar_pc_was_reverted( 90 ), 'marked held' );

echo "9a. wp_update_post() leaves the status unchanged (a filter pinning `future`): held by the direct write, verified\n";
pcbs_reset( array( 'db_status' => array( 91 => 'future' ), 'db_date' => array( 91 => $past ), 'doing_cron' => true, 'update_mode' => 'pinned' ) );
pcbs_cron( 91 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 1 === count( $GLOBALS['pcbs']['updates'] ) && 1 === count( $GLOBALS['pcbs']['direct_writes'] ), 'tried the API, then the direct write' );
pcbs_assert( array( 'ID' => 91, 'post_status' => 'future' ) === ( $GLOBALS['pcbs']['direct_writes'][0][1] ?? null ), 'direct write is compare-and-set on the expected status' );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][91] && true === ( $issue['held'] ?? null ), 'stored pending; recorded held' );
pcbs_assert( array( array( 91, 'future->pending' ) ) === $GLOBALS['pcbs']['transitions'], 'the skipped status transition is dispatched once' );
pcbs_assert( 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'core publisher left attached' );

echo "9b. BLOCKER: the hold does not land at all (update pinned, direct write fails): fail closed, recorded NOT held\n";
pcbs_reset( array( 'db_status' => array( 92 => 'future' ), 'db_date' => array( 92 => $past ), 'doing_cron' => true, 'update_mode' => 'pinned', 'direct_fails' => true ) );
pcbs_cron( 92 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 'future' === $GLOBALS['pcbs']['db_status'][92], 'row still future (the hold really did not land)' );
pcbs_assert( false === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'core publisher detached for this dispatch, so it cannot publish the post' );
pcbs_assert( false === ( $issue['held'] ?? null ) && 'future' === ( $issue['stored_status'] ?? null ) && true === ( $issue['publish_blocked'] ?? null ), 'record: held=false, stored future, publish blocked' );
$fired = array_values( array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_schedule_failed' === $a[0]; } ) );
pcbs_assert( 1 === count( $fired ) && false === ( $fired[0][4]['held'] ?? null ), 'failure action fired once, carrying held=false' );
pcbs_assert( ! rendar_pc_was_reverted( 92 ) && ! in_array( 92, rendar_pc_prechecked_registry(), true ), 'NOT marked held or handled: the transition fallback stays armed' );
pcbs_assert( array() === $GLOBALS['pcbs']['transitions'], 'no transition claimed' );
if ( function_exists( 'rendar_pc_restore_core_publish' ) ) {
	rendar_pc_restore_core_publish();
}
pcbs_assert( 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ) && false === has_action( 'publish_future_post', 'rendar_pc_restore_core_publish' ), 'end of dispatch: core publisher re-attached at 10, restorer gone' );

echo "9c. wp_update_post() returns a WP_Error: direct write holds it\n";
pcbs_reset( array( 'db_status' => array( 93 => 'future' ), 'db_date' => array( 93 => $past ), 'doing_cron' => true, 'update_mode' => 'error' ) );
pcbs_cron( 93 );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][93] && true === ( $GLOBALS['pcbs']['issues'][0]['held'] ?? null ), 'held by the direct write, recorded held' );

echo "9d. Transition fallback (wp_publish_post() outside cron), take-down fails: recorded as LIVE, not held\n";
pcbs_reset( array( 'db_status' => array( 94 => 'publish' ), 'db_date' => array( 94 => $past ), 'update_mode' => 'pinned', 'direct_fails' => true ) );
pcbs_fallback( 94 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( false === ( $issue['held'] ?? null ) && 'publish' === ( $issue['stored_status'] ?? null ) && 'publish-transition' === ( $issue['path'] ?? null ), 'record: held=false, stored publish, publish-transition path' );
pcbs_assert( ! rendar_pc_was_reverted( 94 ) && function_exists( 'rendar_pc_failure_recorded_registry' ) && in_array( 94, rendar_pc_failure_recorded_registry(), true ), 'not marked held (it is live); failure registered so the record survives the rest of the transition' );
pcbs_assert( array( 'ID' => 94, 'post_status' => 'publish' ) === ( $GLOBALS['pcbs']['direct_writes'][0][1] ?? null ), 'direct take-down attempted against publish' );

echo "9e. Transition fallback, update pinned but direct write lands: taken down, recorded held via the transition path\n";
pcbs_reset( array( 'db_status' => array( 95 => 'publish' ), 'db_date' => array( 95 => $past ), 'update_mode' => 'pinned' ) );
pcbs_fallback( 95 );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][95] && true === ( $GLOBALS['pcbs']['issues'][0]['held'] ?? null ) && 'publish-transition' === ( $GLOBALS['pcbs']['issues'][0]['path'] ?? null ), 'pending stored, recorded held, path says it went through publish' );
pcbs_assert( array( array( 95, 'publish->pending' ) ) === $GLOBALS['pcbs']['transitions'], 'publish -> pending dispatched' );

echo "9f. Save-time stored check whose demotion does not land: recorded NOT held, the not-held email (2e; until 2d it claimed nothing)\n";
pcbs_reset( array_merge( pcbs_metabox_request( 96 ), array( 'db_status' => array( 96 => 'future' ), 'stored_blocking' => true, 'update_mode' => 'pinned', 'direct_fails' => true ) ) );
list( $data, $postarr ) = pcbs_write( 96, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
pcbs_land( 96 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 'future' === $GLOBALS['pcbs']['db_status'][96] && 1 === count( $GLOBALS['pcbs']['issues'] ) && false === ( $issue['held'] ?? null ) && 'future' === ( $issue['stored_status'] ?? null ) && 'stored-check' === ( $issue['path'] ?? null ), 'one record: held=false, stored future, stored-check path' );
pcbs_assert( ! array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_backstop_demoted' === $a[0]; } ), 'no demotion action (it did not demote)' );
$m = $GLOBALS['pcbs']['mail'][0] ?? array();
pcbs_assert( 1 === count( $GLOBALS['pcbs']['mail'] ) && false !== strpos( $m['subject'] ?? '', 'could not be held back' ) && false !== strpos( $m['message'] ?? '', 'still scheduled' ) && false === strpos( $m['message'] ?? '', 'saved as Pending' ), 'one not-held email: still scheduled, check it now' );
pcbs_assert( false === ( $GLOBALS['pcbs']['transients']['rendar_pc_notice_175']['held'] ?? null ), 'notice says not held' );
pcbs_reset( array_merge( pcbs_metabox_request( 97 ), array( 'db_status' => array( 97 => 'future' ), 'stored_blocking' => true, 'update_mode' => 'pinned' ) ) );
list( $data, $postarr ) = pcbs_write( 97, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
pcbs_land( 97 );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][97] && 1 === count( $GLOBALS['pcbs']['issues'] ) && true === ( $GLOBALS['pcbs']['issues'][0]['held'] ?? null ), 'pinned update, direct write lands: held and recorded held' );

echo "9g. BLOCK (2d): update pinned, direct write lands, a transition listener re-saves it as future: NOT held, fail closed\n";
pcbs_reset( array( 'db_status' => array( 98 => 'future' ), 'db_date' => array( 98 => $past ), 'doing_cron' => true, 'update_mode' => 'pinned', 'reopen' => array( 'to' => 'future', 'on' => 'dispatch' ) ) );
pcbs_cron( 98 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 1 === $GLOBALS['pcbs']['reopened'] && array( array( 98, 'future->pending' ) ) === $GLOBALS['pcbs']['transitions'], 'the direct write landed, its transition was dispatched, and the listener really reopened the post' );
pcbs_assert( 'future' === $GLOBALS['pcbs']['db_status'][98], 'row is future again' );
pcbs_assert( false === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'core publisher detached for this dispatch, so it cannot publish the reopened post' );
pcbs_assert( false === ( $issue['held'] ?? null ) && 'future' === ( $issue['stored_status'] ?? null ) && true === ( $issue['publish_blocked'] ?? null ) && 'cron-event' === ( $issue['path'] ?? null ), 'record: held=false, stored future, publish blocked, cron-event' );
$fired = array_values( array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_schedule_failed' === $a[0]; } ) );
pcbs_assert( 1 === count( $fired ) && false === ( $fired[0][4]['held'] ?? null ), 'failure action (the email) fired once, carrying held=false' );
pcbs_assert( ! rendar_pc_was_reverted( 98 ) && ! in_array( 98, rendar_pc_prechecked_registry(), true ), 'NOT marked held or handled: the transition fallback stays armed' );
rendar_pc_restore_core_publish();
pcbs_assert( 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'end of dispatch: core publisher re-attached at 10' );

echo "9h. 2d: wp_update_post() works but the listener reopens on every pending transition (update and direct): NOT held\n";
pcbs_reset( array( 'db_status' => array( 99 => 'future' ), 'db_date' => array( 99 => $past ), 'doing_cron' => true, 'reopen' => array( 'to' => 'future', 'on' => 'always' ) ) );
pcbs_cron( 99 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 1 === count( $GLOBALS['pcbs']['updates'] ) && 1 === count( $GLOBALS['pcbs']['direct_writes'] ) && 2 === $GLOBALS['pcbs']['reopened'], 'update path read after its own listeners (reopened), then the direct path, reopened again' );
pcbs_assert( 'future' === $GLOBALS['pcbs']['db_status'][99] && false === ( $issue['held'] ?? null ) && 'future' === ( $issue['stored_status'] ?? null ), 'record: held=false, stored future' );
pcbs_assert( false === has_action( 'publish_future_post', 'check_and_publish_future_post' ) && true === ( $issue['publish_blocked'] ?? null ), 'failed closed' );
rendar_pc_restore_core_publish();

echo "9i. 2d positive control: listener reopens only inside wp_update_post(); the direct write then holds and nothing reopens it\n";
pcbs_reset( array( 'db_status' => array( 100 => 'future' ), 'db_date' => array( 100 => $past ), 'doing_cron' => true, 'reopen' => array( 'to' => 'future', 'on' => 'update-once' ) ) );
pcbs_cron( 100 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 1 === $GLOBALS['pcbs']['reopened'] && 1 === count( $GLOBALS['pcbs']['direct_writes'] ), 'reopened once, direct write used' );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][100] && true === ( $issue['held'] ?? null ) && rendar_pc_was_reverted( 100 ), 'held (the re-read after the dispatch says pending), recorded held' );
pcbs_assert( 10 === has_action( 'publish_future_post', 'check_and_publish_future_post' ), 'core publisher left attached (it will find a pending post)' );

echo "9j. 2d: transition fallback, direct take-down lands, a listener republishes it: recorded LIVE, not held\n";
pcbs_reset( array( 'db_status' => array( 101 => 'publish' ), 'db_date' => array( 101 => $past ), 'update_mode' => 'pinned', 'reopen' => array( 'to' => 'publish', 'on' => 'dispatch' ) ) );
pcbs_fallback( 101 );
$issue = $GLOBALS['pcbs']['issues'][0] ?? array();
pcbs_assert( 'publish' === $GLOBALS['pcbs']['db_status'][101] && false === ( $issue['held'] ?? null ) && 'publish' === ( $issue['stored_status'] ?? null ) && 'publish-transition' === ( $issue['path'] ?? null ), 'record: held=false, stored publish, publish-transition' );
pcbs_assert( ! rendar_pc_was_reverted( 101 ) && in_array( 101, rendar_pc_failure_recorded_registry(), true ), 'not marked held; failure registered so the record survives the transition' );

echo "9k. 2d/2e: save-time stored check whose hold a listener reopens: recorded NOT held, not-held email\n";
pcbs_reset( array_merge( pcbs_metabox_request( 102 ), array( 'db_status' => array( 102 => 'future' ), 'stored_blocking' => true, 'update_mode' => 'pinned', 'reopen' => array( 'to' => 'future', 'on' => 'dispatch' ) ) ) );
list( $data, $postarr ) = pcbs_write( 102, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
pcbs_land( 102 );
pcbs_assert( 1 === $GLOBALS['pcbs']['reopened'] && 'future' === $GLOBALS['pcbs']['db_status'][102], 'the listener reopened the post' );
pcbs_assert( 1 === count( $GLOBALS['pcbs']['issues'] ) && false === ( $GLOBALS['pcbs']['issues'][0]['held'] ?? null ) && 'future' === ( $GLOBALS['pcbs']['issues'][0]['stored_status'] ?? null ), 'recorded held=false, stored future' );
pcbs_assert( ! array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_backstop_demoted' === $a[0]; } ) && 1 === count( $GLOBALS['pcbs']['mail'] ) && false !== strpos( $GLOBALS['pcbs']['mail'][0]['subject'], 'could not be held back' ), 'no demotion action; one not-held email' );

echo "10. 2e: one finalizer, after the outer save, from an uncached read\n";
/**
 * Every assertion about "what was claimed" for one post: records, emails, actions.
 */
function pcbs_claims( $id ) {
	$records = array_values( array_filter( $GLOBALS['pcbs']['issues'], function ( $r ) use ( $id ) { return $id === $r['post_id']; } ) );
	$demoted = array_values( array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) use ( $id ) { return 'rendar_prepublish_checks_backstop_demoted' === $a[0] && $id === $a[1]; } ) );
	return array( $records, $GLOBALS['pcbs']['mail'], $demoted );
}
function pcbs_no_held_claim( $records, $mails, $demoted ) {
	foreach ( $records as $r ) {
		if ( ! empty( $r['held'] ) ) {
			return false;
		}
	}
	foreach ( $mails as $m ) {
		if ( false !== strpos( $m['message'], 'saved as Pending' ) || false !== strpos( $m['message'], 'moved back to Pending' ) || false !== strpos( $m['subject'], 'was not scheduled' ) || false !== strpos( $m['subject'], 'was not published' ) ) {
			return false;
		}
	}
	return array() === $demoted;
}

echo "10a. Backstop demotes a save of a scheduled post to pending; a transition listener later in the SAME save reopens it to `future`\n";
pcbs_reset( array( 'db_status' => array( 110 => 'future' ), 'reopen' => array( 'to' => 'future', 'on' => 'save' ) ) );
$d = pcbs_core_save( 110, 'future' );
list( $records, $mails, $demoted ) = pcbs_claims( 110 );
pcbs_assert( 'pending' === $d['post_status'] && 1 === $GLOBALS['pcbs']['reopened'] && 'future' === $GLOBALS['pcbs']['db_status'][110], 'the backstop demoted the write to pending, and the listener really reopened it to future' );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'future' === $records[0]['stored_status'] && 'backstop' === $records[0]['source'] && 'save' === $records[0]['path'], 'finalizer recorded NOT held, stored future (source backstop, path save)' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'ACTION NEEDED' ) && false !== strpos( $mails[0]['subject'], 'could not be held back' ) && false !== strpos( $mails[0]['message'], 'could NOT be held back' ) && false !== strpos( $mails[0]['message'], 'still scheduled' ) && false !== strpos( $mails[0]['message'], 'Current status: future' ), 'one NOT-held email: still scheduled, current status future, check it now' );
pcbs_assert( pcbs_no_held_claim( $records, $mails, $demoted ), 'never claims held: no held record, no "saved as Pending" email, no backstop_demoted action' );
pcbs_assert( false === ( $GLOBALS['pcbs']['transients']['rendar_pc_notice_175']['held'] ?? null ) && 'future' === ( $GLOBALS['pcbs']['transients']['rendar_pc_notice_175']['stored_status'] ?? null ), 'the admin notice says not held, status future' );

echo "10b. Same, but the listener reopens it to `publish`\n";
pcbs_reset( array( 'db_status' => array( 111 => 'future' ), 'reopen' => array( 'to' => 'publish', 'on' => 'save' ) ) );
pcbs_core_save( 111, 'future' );
list( $records, $mails, $demoted ) = pcbs_claims( 111 );
pcbs_assert( 1 === $GLOBALS['pcbs']['reopened'] && 'publish' === $GLOBALS['pcbs']['db_status'][111], 'the listener really republished it' );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'], 'finalizer recorded NOT held, stored publish' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'could not be held back' ) && false !== strpos( $mails[0]['message'], 'LIVE now' ) && false !== strpos( $mails[0]['message'], 'Current status: publish' ), 'one NOT-held email: LIVE now, check it now' );
pcbs_assert( pcbs_no_held_claim( $records, $mails, $demoted ), 'never claims held' );

echo "10b'. Same two, the publish attempt (pending -> publish) reopened to publish\n";
pcbs_reset( array( 'db_status' => array( 112 => 'pending' ), 'reopen' => array( 'to' => 'publish', 'on' => 'save' ) ) );
pcbs_core_save( 112, 'publish' );
list( $records, $mails, $demoted ) = pcbs_claims( 112 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'] && 1 === count( $mails ) && false !== strpos( $mails[0]['message'], 'tried to publish' ) && false !== strpos( $mails[0]['message'], 'LIVE now' ) && pcbs_no_held_claim( $records, $mails, $demoted ), 'recorded and emailed NOT held, LIVE' );

echo "10c. Positive control: nothing reopens it -> held, the held email, the demotion action\n";
pcbs_reset( array( 'db_status' => array( 113 => 'future' ) ) );
pcbs_core_save( 113, 'future' );
list( $records, $mails, $demoted ) = pcbs_claims( 113 );
pcbs_assert( 1 === count( $records ) && true === $records[0]['held'] && 'pending' === $records[0]['stored_status'], 'recorded held, stored pending' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'Article was not scheduled' ) && false !== strpos( $mails[0]['message'], 'saved as Pending instead' ) && 1 === count( $demoted ), 'held email, backstop_demoted fired once' );

echo "10d. A listener that re-saves the post (a nested write) and reopens it: nothing is finalized until the OUTER save ends\n";
pcbs_reset( array( 'db_status' => array( 114 => 'future' ) ) );
$seen_mid = null;
pcbs_core_save(
	114,
	'future',
	function ( $id ) use ( &$seen_mid ) {
		// save_post callback: wp_update_post( future ) of the same post, fully nested.
		pcbs_issue_enter( $id );
		$GLOBALS['pcbs']['db_status'][ $id ] = 'future';
		pcbs_issue_leave( $id );
		$seen_mid = count( $GLOBALS['pcbs']['issues'] );
	}
);
list( $records, $mails ) = pcbs_claims( 114 );
pcbs_assert( 0 === $seen_mid, 'the nested write landing did not finalize anything (the outer save was still open)' );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'future' === $records[0]['stored_status'] && 1 === count( $mails ), 'finalized once, at the outer save: NOT held, stored future, one email' );

echo "10e. A brand-new post: the intent is bound to its ID at its first transition and finalized when its insert ends\n";
pcbs_reset( array( 'new_id' => 115 ) );
pcbs_core_save( 0, 'publish' );
list( $records, $mails ) = pcbs_claims( 115 );
pcbs_assert( 1 === count( $records ) && true === $records[0]['held'] && 'publish' === $records[0]['intended_status'] && function_exists( 'rendar_pc_pending_issue' ) && null === rendar_pc_pending_issue( 0 ), 'recorded against the new ID, held; nothing left queued under 0' );
pcbs_reset( array( 'new_id' => 116, 'reopen' => array( 'to' => 'publish', 'on' => 'save' ) ) );
pcbs_core_save( 0, 'publish' );
list( $records, $mails, $demoted ) = pcbs_claims( 116 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'] && pcbs_no_held_claim( $records, $mails, $demoted ), 'new post reopened to publish: NOT held, never claimed held' );

echo "10f. Cron: the hold stands when the re-check returns, then something between 5 and the end of the dispatch reopens it\n";
pcbs_reset( array( 'db_status' => array( 117 => 'future' ), 'db_date' => array( 117 => $past ), 'doing_cron' => true ) );
rendar_pc_precheck_future_post( 117 );
pcbs_assert( array() === $GLOBALS['pcbs']['issues'] && 'pending' === $GLOBALS['pcbs']['db_status'][117], 'held, and nothing recorded yet (the dispatch is still running)' );
$GLOBALS['pcbs']['db_status'][117] = 'future';
pcbs_end_cron_dispatch( 117 );
list( $records, $mails ) = pcbs_claims( 117 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'future' === $records[0]['stored_status'] && 'cron-event' === $records[0]['path'], 'recorded at the end of the dispatch: NOT held, stored future' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'could not be held back' ) && false === strpos( $mails[0]['subject'], 'did not publish' ), 'the not-held email, not "did not publish"' );

echo "10g. Fallback: the take-down stands, then a later listener in wp_publish_post()'s outer dispatch republishes it\n";
pcbs_reset( array( 'db_status' => array( 118 => 'publish' ), 'db_date' => array( 118 => $past ) ) );
rendar_pc_recheck_scheduled( 'publish', 'future', get_post( 118 ) );
pcbs_assert( 'pending' === $GLOBALS['pcbs']['db_status'][118] && array() === $GLOBALS['pcbs']['issues'], 'taken down; nothing recorded inside the transition' );
pcbs_issue_enter( 118 ); // publish_post listener: wp_update_post( publish ), fully nested
$GLOBALS['pcbs']['db_status'][118] = 'publish';
pcbs_issue_leave( 118 );
pcbs_assert( array() === $GLOBALS['pcbs']['issues'], 'the nested re-save did not finalize (wp_publish_post()\'s own wp_after_insert_post is still to come)' );
pcbs_issue_leave( 118 ); // wp_publish_post()'s wp_after_insert_post
list( $records, $mails ) = pcbs_claims( 118 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'] && 1 === count( $mails ) && false !== strpos( $mails[0]['message'], 'LIVE now' ) && false === strpos( $mails[0]['subject'], 'taken back down' ), 'recorded and emailed LIVE, not "taken back down"' );

echo "10h. Shutdown net: a demoted write that never reached wp_after_insert_post is finalized from the row; an unbound new-post intent is dropped\n";
pcbs_reset( array( 'db_status' => array( 119 => 'future' ) ) );
list( $data, $postarr ) = pcbs_write( 119, 'future', $content );
rendar_pc_insert_backstop( $data, $postarr );
pcbs_issue_enter( 119 ); // then the database write fails: the row stays future, no transition, no wp_after_insert_post
list( $data, $postarr ) = pcbs_write( 0, 'publish', $content );
rendar_pc_insert_backstop( $data, $postarr );
if ( function_exists( 'rendar_pc_finalize_leftover_issues' ) ) {
	pcbs_assert( false !== has_action( 'shutdown', 'rendar_pc_finalize_leftover_issues' ), 'the net is registered by the queue' );
	rendar_pc_finalize_leftover_issues();
}
list( $records, $mails ) = pcbs_claims( 119 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'future' === $records[0]['stored_status'] && 1 === count( $GLOBALS['pcbs']['issues'] ), 'NOT held (the row is still future); the never-inserted post recorded nothing' );

echo "10i. `held` has no default, and an old record without one is read from the post, not assumed\n";
pcbs_reset( array( 'db_status' => array( 120 => 'future' ) ) );
$r = rendar_pc_record_issue( 120, 'cron', array( 'checks' => array() ), array( 'post-tags' ), array( 'intended_status' => 'future' ) );
pcbs_assert( false === $r['held'] && '' === $r['stored_status'], 'a record written without a measurement says not held, status unknown' );
$GLOBALS['pcbs']['meta'][120][ RENDAR_PC_META_SCHEDULE_FAILURE ] = array( 'source' => 'cron', 'checks' => array( 'post-tags' ) );
$g = rendar_pc_get_issue( 120 );
pcbs_assert( false === $g['held'] && 'future' === $g['stored_status'], 'a pre-2c row on a future post reads not held, status future' );
$GLOBALS['pcbs']['db_status'][120] = 'pending';
$g = rendar_pc_get_issue( 120 );
pcbs_assert( true === $g['held'] && 'pending' === $g['stored_status'], 'the same row on a pending post reads held' );

echo "11. a cron intent is owned by its dispatch; a nested same-post save does not finalize it\n";
/**
 * One cron dispatch: start (PHP_INT_MIN), re-check (5), then $between (what
 * other publish_future_post callbacks and listeners do), then the end.
 * Returns how many records existed just before the end of the dispatch.
 */
function pcbs_cron_with( $post_id, $between ) {
	pcbs_start_cron_dispatch( $post_id );
	rendar_pc_precheck_future_post( $post_id );
	$between( $post_id );
	$before_end = count( $GLOBALS['pcbs']['issues'] );
	pcbs_end_cron_dispatch( $post_id );
	return $before_end;
}
/**
 * A same-post wp_update_post() fully nested in the dispatch: counted open, row
 * written, wp_after_insert_post (the finalizer at PHP_INT_MAX).
 */
function pcbs_nested_save( $post_id, $status ) {
	pcbs_issue_enter( $post_id );
	$GLOBALS['pcbs']['db_status'][ $post_id ] = $status;
	pcbs_issue_leave( $post_id );
}

echo "11a. hold -> nested same-post save (still pending) -> reopen to publish, all inside the dispatch\n";
pcbs_reset( array( 'db_status' => array( 130 => 'future' ), 'db_date' => array( 130 => $past ), 'doing_cron' => true ) );
$seen_after_nested = null;
$held_at_start     = null;
$before_end        = pcbs_cron_with(
	130,
	function ( $id ) use ( &$seen_after_nested, &$held_at_start ) {
		$held_at_start = $GLOBALS['pcbs']['db_status'][ $id ];
		pcbs_nested_save( $id, 'pending' );                   // e.g. a listener touching the post
		$seen_after_nested = count( $GLOBALS['pcbs']['issues'] );
		$GLOBALS['pcbs']['db_status'][ $id ] = 'publish';     // a later callback reopens it
	}
);
list( $records, $mails ) = pcbs_claims( 130 );
pcbs_assert( 'pending' === $held_at_start && rendar_pc_was_reverted( 130 ), 'the re-check held it (pending in the row) before the nested save' );
pcbs_assert( 0 === $seen_after_nested, 'the nested save reaching wp_after_insert_post did NOT finalize the cron intent (an earlier revision recorded held=true here)' );
pcbs_assert( 0 === $before_end, 'nothing recorded before the end of the dispatch' );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'] && 'cron-event' === $records[0]['path'] && 'cron' === $records[0]['source'], 'finalized once, at the end of the dispatch: NOT held, stored publish, cron-event' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'could not be held back' ) && false === strpos( $mails[0]['subject'], 'did not publish' ), 'one not-held email, never "did not publish"' );
$fired = array_values( array_filter( $GLOBALS['pcbs']['actions'], function ( $a ) { return 'rendar_prepublish_checks_schedule_failed' === $a[0] && 130 === $a[1]; } ) );
pcbs_assert( 1 === count( $fired ) && false === ( $fired[0][4]['held'] ?? null ), 'schedule_failed fired once, carrying held=false' );
pcbs_assert( null === rendar_pc_pending_issue( 130 ), 'nothing left queued' );

echo "11b. Same, reopened to future\n";
pcbs_reset( array( 'db_status' => array( 131 => 'future' ), 'db_date' => array( 131 => $past ), 'doing_cron' => true ) );
pcbs_cron_with(
	131,
	function ( $id ) {
		pcbs_nested_save( $id, 'pending' );
		$GLOBALS['pcbs']['db_status'][ $id ] = 'future';
	}
);
list( $records, $mails ) = pcbs_claims( 131 );
pcbs_assert( 1 === count( $records ) && false === $records[0]['held'] && 'future' === $records[0]['stored_status'] && 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'could not be held back' ), 'NOT held, stored future, one not-held email' );

echo "11c. The reopen IS the nested save (wp_update_post( publish ) inside the dispatch)\n";
pcbs_reset( array( 'db_status' => array( 132 => 'future' ), 'db_date' => array( 132 => $past ), 'doing_cron' => true ) );
$seen_after_nested = null;
pcbs_cron_with(
	132,
	function ( $id ) use ( &$seen_after_nested ) {
		pcbs_nested_save( $id, 'publish' );
		$seen_after_nested = count( $GLOBALS['pcbs']['issues'] );
	}
);
list( $records, $mails ) = pcbs_claims( 132 );
pcbs_assert( 0 === $seen_after_nested && 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'], 'not finalized by its own reopening save; recorded NOT held, publish' );

echo "11d. Positive control: nested same-post save, nothing reopens -> held, the held email, at the end of the dispatch\n";
pcbs_reset( array( 'db_status' => array( 133 => 'future' ), 'db_date' => array( 133 => $past ), 'doing_cron' => true ) );
$seen_after_nested = null;
pcbs_cron_with(
	133,
	function ( $id ) use ( &$seen_after_nested ) {
		pcbs_nested_save( $id, 'pending' );
		$seen_after_nested = count( $GLOBALS['pcbs']['issues'] );
	}
);
list( $records, $mails ) = pcbs_claims( 133 );
pcbs_assert( 0 === $seen_after_nested && 1 === count( $records ) && true === $records[0]['held'] && 'pending' === $records[0]['stored_status'], 'recorded held, stored pending, only at the end' );
pcbs_assert( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'did not publish' ), 'the held "did not publish" email' );

echo "11e. Ownership: a non-cron intent outside any dispatch still finalizes at its outermost write (2e unchanged)\n";
pcbs_reset( array( 'db_status' => array( 134 => 'future' ) ) );
pcbs_core_save( 134, 'future' );
list( $records ) = pcbs_claims( 134 );
pcbs_assert( 1 === count( $records ) && true === $records[0]['held'] && null === rendar_pc_pending_issue( 134 ), 'backstop save: finalized by its own outermost write, nothing left queued' );

echo "11f. The fallback reached INSIDE a dispatch (wp_publish_post() by a callback between 5 and the end) is dispatch-owned too\n";
pcbs_reset( array( 'db_status' => array( 135 => 'publish' ), 'db_date' => array( 135 => $past ) ) );
pcbs_start_cron_dispatch( 135 );
rendar_pc_recheck_scheduled( 'publish', 'future', get_post( 135 ) ); // taken down to pending
pcbs_issue_leave( 135 );                                                // wp_publish_post()'s own wp_after_insert_post
$seen_mid = count( $GLOBALS['pcbs']['issues'] );
$GLOBALS['pcbs']['db_status'][135] = 'publish';                          // reopened later in the dispatch
pcbs_end_cron_dispatch( 135 );
list( $records ) = pcbs_claims( 135 );
pcbs_assert( 0 === $seen_mid && 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'] && 'publish-transition' === $records[0]['path'], 'not finalized at wp_publish_post()\'s wp_after_insert_post; recorded LIVE at the end of the dispatch' );

echo "11g. A publish_future_post dispatch nested inside another for the same post: the OUTER end finalizes\n";
pcbs_reset( array( 'db_status' => array( 136 => 'future' ), 'db_date' => array( 136 => $past ), 'doing_cron' => true ) );
pcbs_start_cron_dispatch( 136 );
pcbs_start_cron_dispatch( 136 );
rendar_pc_precheck_future_post( 136 );
pcbs_end_cron_dispatch( 136 );
$seen_mid = count( $GLOBALS['pcbs']['issues'] );
$GLOBALS['pcbs']['db_status'][136] = 'publish';
pcbs_end_cron_dispatch( 136 );
list( $records ) = pcbs_claims( 136 );
pcbs_assert( 0 === $seen_mid && 1 === count( $records ) && false === $records[0]['held'] && 'publish' === $records[0]['stored_status'], 'inner end did not finalize; outer end recorded NOT held, publish' );

echo "11h. Shutdown net still finalizes a dispatch-owned intent whose dispatch never ended\n";
pcbs_reset( array( 'db_status' => array( 137 => 'future' ), 'db_date' => array( 137 => $past ), 'doing_cron' => true ) );
pcbs_start_cron_dispatch( 137 );
rendar_pc_precheck_future_post( 137 );
pcbs_nested_save( 137, 'pending' );
rendar_pc_finalize_leftover_issues();
list( $records ) = pcbs_claims( 137 );
pcbs_assert( 1 === count( $records ) && true === $records[0]['held'], 'finalized at shutdown from the row' );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
