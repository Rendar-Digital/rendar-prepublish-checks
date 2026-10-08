<?php
/** Stub contract: applicability, scope, Settings API markup. Not real WP acceptance. */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );
set_error_handler( function ( $n, $message ) { throw new Exception( $message ); } );
function absint( $n ) { return abs( (int) $n ); }
function sanitize_email( $s ) { return $s; }
function is_email( $s ) { return false; }
$GLOBALS['post_types'] = array(
	'post' => (object) array( 'name' => 'post', 'label' => 'Posts', 'public' => true, 'show_in_rest' => true ),
	'review' => (object) array( 'name' => 'review', 'label' => 'Reviews', 'public' => true, 'show_in_rest' => true ),
	'no_meta' => (object) array( 'name' => 'no_meta', 'label' => 'No Meta', 'public' => true, 'show_in_rest' => true ),
	'private' => (object) array( 'name' => 'private', 'label' => 'Private', 'public' => false, 'show_in_rest' => true ),
	'hidden' => (object) array( 'name' => 'hidden', 'label' => 'Hidden', 'public' => true, 'show_in_rest' => false ),
);
$GLOBALS['type_supports']['review'] = array( 'editor', 'custom-fields' );
check( array( 'post' ) === rendar_pc_get_post_types(), 'built-in post supports custom-fields and remains default eligible' );
check( ! isset( rendar_pc_eligible_post_types()['no_meta'] ), 'REST editor CPT lacking custom-fields is ineligible' );
check( array() === rendar_pc_sanitize_post_types( array( 'no_meta' ) ), 'unsupported CPT cannot enter scope' );
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array( 'post_types' => array( 'no_meta' ) );
check( array() === rendar_pc_get_post_types(), 'stale unsupported scope cannot run' );
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array( 'post_types' => 'post' );
check( array( 'post' ) === rendar_pc_get_post_types(), 'scalar fallback intersects eligibility' );
$GLOBALS['type_supports']['post'] = array( 'editor' );
check( array() === rendar_pc_get_post_types(), 'scalar fallback does not revive ineligible post when no types qualify' );
check( array() === rendar_pc_sanitize_settings( array( 'post_types' => array( 'post' ) ) )['post_types'], 'submitted post also ineligible without custom-fields' );
$GLOBALS['type_supports']['post'] = array( 'editor', 'custom-fields' );
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array();
// Simulate theme CPT registration at default and later init priorities. The
// metadata must already be available when REST initializes its post schema.
add_action( 'init', function () {
	$GLOBALS['post_types']['default_cpt'] = (object) array( 'name' => 'default_cpt', 'label' => 'Default', 'public' => true, 'show_in_rest' => true );
	$GLOBALS['type_supports']['default_cpt'] = array( 'editor', 'custom-fields' );
}, 10 );
add_action( 'init', function () {
	$GLOBALS['post_types']['late_cpt'] = (object) array( 'name' => 'late_cpt', 'label' => 'Late', 'public' => true, 'show_in_rest' => true );
	$GLOBALS['type_supports']['late_cpt'] = array( 'editor', 'custom-fields' );
}, 100 );
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array( 'post_types' => array( 'post', 'review', 'default_cpt', 'late_cpt', 'no_meta' ) );
fire_hook( 'init' );
foreach ( array( 'post', 'review', 'default_cpt', 'late_cpt' ) as $type ) {
	check( isset( rendar_pc_eligible_post_types()[ $type ] ), "$type eligible" );
}
add_action( 'rest_api_init', function () {
	foreach ( array( 'post', 'review', 'default_cpt', 'late_cpt' ) as $type ) {
		$found = array_values( array_filter( $GLOBALS['meta'], function ( $entry ) use ( $type ) {
			return $type === $entry['type'] && RENDAR_PC_META_DECORATIVE === $entry['key'] && isset( $entry['args']['show_in_rest']['schema'] );
		} ) );
		check( 1 === count( $found ), "$type decorative meta registered before REST schema" );
	}
	check( ! in_array( 'no_meta', array_column( $GLOBALS['meta'], 'type' ), true ), 'unsupported CPT has no REST meta' );
} );
fire_hook( 'rest_api_init' );
$input = array_merge( rendar_pc_default_settings(), array( 'post_types' => array( '', 'review', 'review', 'no_meta', 'private', 'hidden', 'evil', array( 'post' ) ) ) );
$clean = rendar_pc_sanitize_settings( $input );
check( array( 'review' ) === $clean['post_types'], 'forged and ineligible types discarded' );
$GLOBALS['options'][ RENDAR_PC_OPTION ] = $clean;
check( array( 'review' ) === rendar_pc_get_post_types(), 'stored selection roundtrip' );
$GLOBALS['options'][ RENDAR_PC_OPTION ]['post_types'] = array( 'evil', 'review' );
check( array( 'review' ) === rendar_pc_get_post_types(), 'old forged stored scope cannot run' );
check( array() === rendar_pc_sanitize_settings( array( 'post_types' => array( '' ) ) )['post_types'], 'empty checkbox submission clears scope' );

$good = 'rendar_pc_check_featured_image';
add_filter( 'rendar_prepublish_checks_definitions', function ( $checks ) use ( $good ) {
	foreach ( array(
		'bad-version' => array( 'version' => 2 ),
		'unknown-key' => array( 'version' => 1, 'taxonomy' => array( 'category' ) ),
		'bad-tax' => array( 'version' => 1, 'taxonomies' => array( 'made_up' ) ),
		'bad-support' => array( 'version' => 1, 'supports' => array( array( 'editor' ) ) ),
		'bad-dependency' => array( 'version' => 1, 'dependencies' => array( 'other' ) ),
		'bad-list' => array( 'version' => 1, 'taxonomies' => array( 3 => 'category' ) ),
		'bad-object' => array( 'version' => 1, 'taxonomies' => array( 'category', new stdClass() ) ),
		'bad-mixed-objects' => array( 'version' => 1, 'supports' => array( new stdClass(), new stdClass() ) ),
		'null-requirements' => null,
	) as $id => $requirements ) {
		$checks[ $id ] = array( 'callback' => $good, 'requirements' => $requirements );
	}
	$checks['bad-scope'] = array( 'callback' => $good, 'applies_to' => array( array( 'post' ) ) );
	$checks['good-tax'] = array( 'callback' => $good, 'requirements' => array( 'version' => 1, 'taxonomies' => array( 'category' ) ) );
	return $checks;
} );
$options_before = $GLOBALS['options'];
$meta_before = $GLOBALS['post_meta'];
$checks = rendar_pc_get_checks();
foreach ( array( 'bad-version', 'unknown-key', 'bad-tax', 'bad-support', 'bad-dependency', 'bad-list', 'bad-object', 'bad-mixed-objects', 'null-requirements', 'bad-scope' ) as $id ) {
	check( ! isset( $checks[ $id ] ), "$id rejected" );
}
check( isset( $checks['good-tax'] ), 'valid requirement registered' );
check( count( rendar_pc_invalid_definitions() ) >= 10, 'invalid descriptors logged' );
// Lightweight context for gate checks; no post or database required.
$context = new Rendar_PC_Context();
$context->post_type = 'review';
function is_object_in_taxonomy( $type, $tax ) { return in_array( $tax, $GLOBALS['taxonomies'][ $type ] ?? array(), true ); }
$GLOBALS['taxonomies']['review'] = array();
check( RENDAR_PC_STATUS_NA === rendar_pc_requirements_result( $checks['good-tax'], $context )['status'], 'absent taxonomy skips' );
$GLOBALS['taxonomies']['review'] = array( 'category' );
check( null === rendar_pc_requirements_result( $checks['good-tax'], $context ), 'registered empty taxonomy runs' );
check( RENDAR_PC_STATUS_NA === rendar_pc_requirements_result( $checks['featured-image'], $context )['status'], 'unsupported thumbnail skips' );
check( RENDAR_PC_STATUS_UNAVAILABLE === rendar_pc_requirements_result( $checks['required-custom-fields'], $context )['status'], 'missing ACF unavailable' );
$context->content = '<!-- wp:paragraph --><p><img src="x"></p><!-- /wp:paragraph -->';
$context->has_real_blocks = true;
check( RENDAR_PC_STATUS_UNAVAILABLE === rendar_pc_requirements_result( $checks['image-alt-text'], $context )['status'], 'missing WP tag processor unavailable for block content' );
$context->content = '';
check( null === rendar_pc_requirements_result( $checks['image-alt-text'], $context ), 'empty content needs no processor' );
check( $options_before === $GLOBALS['options'] && $meta_before === $GLOBALS['post_meta'], 'requirement evaluation does not change options or meta' );
function do_action( $hook ) { fire_hook( $hook ); }
function settings_fields( $group ) { echo '<input name="_wpnonce" value="stub" />'; }
function esc_html_e( $s, $domain = null ) { echo $s; }
function selected( $a, $b, $echo = true ) { if ( $echo && $a === $b ) echo ' selected="selected"'; }
function checked( $a, $b = true, $echo = true ) { if ( $echo && $a === $b ) echo ' checked="checked"'; }
function esc_textarea( $s ) { return htmlspecialchars( $s ); }
function submit_button() { echo '<button>Save</button>'; }
$GLOBALS['options'][ RENDAR_PC_OPTION ] = $clean;
ob_start();
rendar_pc_render_settings_page();
$html = ob_get_clean();
check( false !== strpos( $html, 'name="_wpnonce"' ) && false !== strpos( $html, 'action="options.php"' ), 'Settings API nonce/action stub' );
check( (bool) preg_match( '/value="review"\\s+checked="checked"/', $html ), 'saved scope checked on render' );
check( false === strpos( $html, 'value="evil"' ) && false === strpos( $html, 'value="private"' ) && false === strpos( $html, 'value="no_meta"' ), 'ineligible scope absent on render' );
$GLOBALS['allowed'] = false;
function wp_die( $message ) { throw new Exception( $message ); }
try { rendar_pc_render_settings_page(); check( false, 'capability denied' ); } catch ( Exception $e ) { check( true, 'capability denied' ); }
restore_error_handler();
echo "\napplicability/settings stub: OK\n";
