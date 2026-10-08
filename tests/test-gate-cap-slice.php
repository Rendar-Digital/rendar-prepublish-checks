<?php
/** Dormant gate contract tests. Stubbed dispatch is NOT a real-core write proof. */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );
require dirname( __DIR__ ) . '/inc/gate.php';

class WP_REST_Request implements ArrayAccess {
	private $params;
	public function __construct( $params ) { $this->params = $params; }
	public function offsetExists( $key ): bool { return isset( $this->params[ $key ] ); }
	#[\ReturnTypeWillChange]
	public function offsetGet( $key ) { return $this->params[ $key ] ?? null; }
	public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}
class WP_REST_Autosaves_Controller { public function create_item( $request ) {} }
class WP_Post { public $ID; public $post_type; public function __construct( $id, $type ) { $this->ID = $id; $this->post_type = $type; } }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }

$token = '{"reason":"bypass","checks":["featured-image"]}';
$GLOBALS['post_types']['post'] = (object) array( 'name' => 'post', 'label' => 'Posts', 'public' => true, 'show_in_rest' => true );
$GLOBALS['posts'][71] = new WP_Post( 71, 'post' );
$GLOBALS['posts'][72] = new WP_Post( 72, 'late_article' );
$GLOBALS['editable_posts'] = array( 71 => true, 72 => true );
add_action( 'init', function () {
	$GLOBALS['type_supports']['late_article'] = array( 'editor', 'custom-fields' );
	$GLOBALS['post_types']['late_article'] = (object) array( 'name' => 'late_article', 'label' => 'Late', 'public' => true, 'show_in_rest' => true );
}, 50 );
$GLOBALS['options'][RENDAR_PC_OPTION] = array( 'post_types' => array( 'post', 'late_article' ) );
fire_hook( 'init' );
check( in_array( 'rendar_pc_rest_gate', hook_callbacks( 'rest_pre_insert_late_article' ), true ), 'late init CPT has gate before REST' );
$registered = array_values( array_filter( $GLOBALS['hooks']['rest_request_before_callbacks'] ?? array(), function ( $entry ) { return 'rendar_pc_autosave_override_gate' === $entry[0]; } ) );
check( 1 === count( $registered ) && 10 === $registered[0][1] && 3 === $registered[0][2], 'autosave guard registered before callbacks at priority 10 with three args' );
$autosave = array( 'callback' => array( new WP_REST_Autosaves_Controller(), 'create_item' ) );
$GLOBALS['allowed'] = false;
foreach ( array( 'post' => 71, 'late_article' => 72 ) as $type => $id ) {
	foreach ( array( 'meta', 'acf' ) as $transport ) {
		foreach ( array( $token, array( 'invalid' ) ) as $value ) {
			$request = new WP_REST_Request( array( 'id' => $id, $transport => array( RENDAR_PC_META_OVERRIDE => $value ) ) );
			$before = rendar_pc_autosave_override_gate( null, $autosave, $request );
			check( $before instanceof WP_Error && 'rendar_prepublish_checks_override_forbidden' === $before->code, "$type $transport autosave token rejected before callback" );
			$prepared = (object) array( 'ID' => $id, 'post_status' => 'draft' );
			$verdict = rendar_pc_rest_gate( $prepared, $request );
			check( $verdict instanceof WP_Error && 'rendar_prepublish_checks_override_forbidden' === $verdict->code, "$type $transport posts controller refuses draft token" );
		}
	}
}
foreach ( array( array(), array( 'meta' => array( RENDAR_PC_META_OVERRIDE => '' ) ), array( 'acf' => array( RENDAR_PC_META_OVERRIDE => null ) ) ) as $params ) {
	$request = new WP_REST_Request( array_merge( array( 'id' => 71 ), $params ) );
	check( null === rendar_pc_autosave_override_gate( null, $autosave, $request ), 'ordinary autosave not blocked' );
}
check( null === rendar_pc_autosave_override_gate( null, $autosave, new WP_REST_Request( array( 'id' => 999, 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) ), 'nonexistent target left to core' );
check( null === rendar_pc_autosave_override_gate( null, array( 'callback' => array( new WP_REST_Autosaves_Controller(), 'get_item' ) ), new WP_REST_Request( array( 'id' => 71, 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) ), 'read route untouched' );
check( rendar_pc_autosave_override_gate( null, $autosave, new WP_REST_Request( array( 'id' => 71, 'meta' => 'malformed' ) ) ) instanceof WP_Error, 'malformed nonempty meta refused' );
$GLOBALS['allowed'] = true;
$GLOBALS['editable_posts'][71] = false;
check( rendar_pc_autosave_override_gate( null, $autosave, new WP_REST_Request( array( 'id' => 71, 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) ) instanceof WP_Error, 'admin without edit_post refused' );
$GLOBALS['editable_posts'][71] = true;
check( null === rendar_pc_autosave_override_gate( null, $autosave, new WP_REST_Request( array( 'id' => 71, 'meta' => array( RENDAR_PC_META_OVERRIDE => $token ) ) ) ), 'authorized admin left to core' );
$GLOBALS['allowed'] = false;
check( '' === rendar_pc_sanitize_override( $token ), 'unprivileged token not stored' );
check( '' === rendar_pc_sanitize_override( '' ), 'empty token accepted' );
echo "\ngate capability slice (stub): OK\n";
