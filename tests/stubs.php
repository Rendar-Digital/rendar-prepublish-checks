<?php
/**
 * Minimal WordPress contract stubs for the boot/gating tests.
 *
 * No database, no network. Hooks, routes and registered meta are captured in
 * globals so a test can assert exactly what the plugin registered when it
 * booted, and fire the init / rest_api_init hooks to drive registration.
 */

error_reporting( E_ALL );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['hooks']        = array();
$GLOBALS['activation']   = array();
$GLOBALS['routes']       = array();
$GLOBALS['meta']         = array();
$GLOBALS['options']      = array();
$GLOBALS['site_options'] = array();
$GLOBALS['post_meta']    = array();
$GLOBALS['allowed']      = true;
$GLOBALS['current_time'] = '2026-01-01 00:00:00';

function check( $ok, $message ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
	echo '.';
}

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = array( $cb, $priority, $args );
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	return add_action( $hook, $cb, $priority, $args );
}

/**
 * Registered callbacks for a hook, ordered by priority then registration order,
 * the way WordPress fires them. A real-WP stub has to honour priority because
 * the plugin leans on it (e.g. rendar_pc_boot at PHP_INT_MAX runs last).
 */
function hook_entries_ordered( $hook ) {
	$entries = $GLOBALS['hooks'][ $hook ] ?? array();
	// Stable sort by priority: decorate with the original index so equal
	// priorities keep insertion order (usort is not stable before PHP 8.0).
	$indexed = array();
	foreach ( $entries as $i => $entry ) {
		$indexed[] = array( $entry, $i );
	}
	usort(
		$indexed,
		function ( $a, $b ) {
			return ( $a[0][1] <=> $b[0][1] ) ?: ( $a[1] <=> $b[1] );
		}
	);
	return array_map( function ( $e ) { return $e[0]; }, $indexed );
}
function fire_hook( $hook, ...$args ) {
	foreach ( hook_entries_ordered( $hook ) as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
	}
}
function hook_callbacks( $hook ) {
	return array_map( function ( $e ) { return $e[0]; }, hook_entries_ordered( $hook ) );
}

/**
 * Snapshot the per-hook callback counts, so a test can assert exactly what a
 * later event (e.g. firing plugins_loaded) added.
 */
function hooks_snapshot() {
	$snap = array();
	foreach ( $GLOBALS['hooks'] as $hook => $entries ) {
		$snap[ $hook ] = count( $entries );
	}
	return $snap;
}

/**
 * The ( hook, callback ) pairs added since a snapshot, in registration order.
 */
function hooks_added_since( array $snap ) {
	$added = array();
	foreach ( $GLOBALS['hooks'] as $hook => $entries ) {
		$before = $snap[ $hook ] ?? 0;
		for ( $i = $before, $n = count( $entries ); $i < $n; $i++ ) {
			$added[] = array( $hook, $entries[ $i ][0] );
		}
	}
	return $added;
}

function register_activation_hook( $file, $cb ) { $GLOBALS['activation'][] = $cb; }
function register_deactivation_hook( $file, $cb ) { $GLOBALS['activation'][] = $cb; }

function get_post_types( $args = array(), $output = 'names' ) {
	$types = $GLOBALS['post_types'] ?? array( 'post' => (object) array( 'name' => 'post', 'label' => 'Posts', 'public' => true, 'show_in_rest' => true ) );
	return 'objects' === $output ? $types : array_keys( $types );
}
function post_type_supports( $type, $support ) {
	return in_array( $support, $GLOBALS['type_supports'][ $type ] ?? ( 'post' === $type ? array( 'editor', 'custom-fields' ) : array( 'editor' ) ), true );
}
function register_post_meta( $type, $key, $args ) {
	$GLOBALS['meta'][] = array( 'type' => $type, 'key' => $key, 'args' => $args );
	return true;
}
function register_rest_route( $ns, $route, $args ) {
	$GLOBALS['routes'][] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
	return true;
}
function registered_routes() {
	return array_map( function ( $r ) { return $r['route']; }, $GLOBALS['routes'] );
}
function registered_meta_keys() {
	return array_map( function ( $m ) { return $m['key']; }, $GLOBALS['meta'] );
}

class WP_REST_Server { const CREATABLE = 'POST'; const READABLE = 'GET'; }
class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; }
}
class WP_REST_Response {
	public $data; public $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}

function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_url( $s ) { return $s; }
function current_user_can( $cap, $id = null ) {
	if ( 'edit_post' === $cap ) {
		return $id && ! empty( $GLOBALS['editable_posts'][ $id ] );
	}
	return ! empty( $GLOBALS['allowed'] );
}
function get_role( $role ) { return $GLOBALS['roles'][ $role ] ?? null; }
class WP_Role {
	public $caps = array();
	public function has_cap( $cap ) { return ! empty( $this->caps[ $cap ] ); }
	public function remove_cap( $cap ) { unset( $this->caps[ $cap ] ); }
}
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_option( $k, $v, $dep = '', $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function current_time( $type, $gmt = 0 ) { return $GLOBALS['current_time']; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
function apply_filters( $hook, $value, ...$args ) {
	// Honour priority and actually run the callbacks, so a test can exercise a
	// registered filter. Each callback receives $value plus the extra args,
	// clamped to the arg count it was registered with, and feeds the next.
	foreach ( hook_entries_ordered( $hook ) as $entry ) {
		$all   = array_merge( array( $value ), $args );
		$value = call_user_func_array( $entry[0], array_slice( $all, 0, $entry[2] ) );
	}
	return $value;
}
function get_site_option( $k, $d = false ) { return $GLOBALS['site_options'][ $k ] ?? $d; }
function update_site_option( $k, $v ) { $GLOBALS['site_options'][ $k ] = $v; return true; }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = $GLOBALS['post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
	return $value;
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['post_meta'][ $post_id ][ $key ] = $value;
	return true;
}
function wp_json_encode( $v ) { return json_encode( $v ); }
function rest_ensure_response( $r ) { return $r; }
function rest_authorization_required_code() { return 403; }
