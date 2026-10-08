<?php
/**
 * In-memory WordPress contract for evaluator tests.
 *
 * Lets the checks run through the real rendar_pc_evaluate() rather than a mock.
 * Fidelity to real WordPress is a limit on what a test proves: an inaccurate
 * stub parser or taxonomy response is not made correct by being shared.
 *
 * It is backed by an in-memory "database" ($GLOBALS['db']) a scenario fills in:
 * posts, thumbnails, terms (with a parent chain), post meta, attachment meta,
 * and an optional ACF layer. No real database, no network.
 *
 * @package Rendar_Prepublish_Checks\Tests
 */

error_reporting( E_ALL );

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

$GLOBALS['hooks']        = array();
$GLOBALS['options']      = array();
$GLOBALS['site_options'] = array();
$GLOBALS['db']           = array(
	'posts'       => array(),  // id => WP_Post
	'thumbnail'   => array(),  // post_id => attachment_id
	'terms'       => array(),  // post_id => [ taxonomy => int[] ]
	'postmeta'    => array(),  // post_id => [ key => value ]
	'term'        => array(),  // term_id => WP_Term
	'attach_meta' => array(),  // id => [ 'width' => int ]
	'attach_url'  => array(),  // id => string
	'taxonomies'  => array(), // post_type => registered taxonomy names
	'acf'         => null,     // null => ACF absent; else [ 'groups'=>[], 'values'=>[post_id=>[name=>val]] ]
);

/* ------------------------------------------------------------------ core objects */

class WP_Post {
	public $ID = 0;
	public $post_type = 'post';
	public $post_status = 'draft';
	public $post_content = '';
	public $post_title = '';
	public $post_excerpt = '';
	public $post_date = '0000-00-00 00:00:00';
	public $post_date_gmt = '0000-00-00 00:00:00';

	public function __construct( array $fields ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

class WP_Term {
	public $term_id = 0;
	public $name = '';
	public $taxonomy = 'category';
	public $parent = 0;

	public function __construct( array $fields ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code;
	}
}
class WP_REST_Server {
	const CREATABLE = 'POST';
	const READABLE  = 'GET';
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}
class WP_Role {
	public $caps = array();
	public function has_cap( $cap ) {
		return ! empty( $this->caps[ $cap ] );
	}
	public function remove_cap( $cap ) {
		unset( $this->caps[ $cap ] );
	}
}

/* ------------------------------------------------------------------ hooks */

function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][ $hook ][] = array( $cb, $priority, $args );
	return true;
}
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	return add_action( $hook, $cb, $priority, $args );
}
function remove_all_filters( $hook ) {
	unset( $GLOBALS['hooks'][ $hook ] );
	return true;
}
function wpstub_hook_entries_ordered( $hook ) {
	$entries = $GLOBALS['hooks'][ $hook ] ?? array();
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
function do_action( $hook, ...$args ) {
	foreach ( wpstub_hook_entries_ordered( $hook ) as $entry ) {
		call_user_func_array( $entry[0], array_slice( $args, 0, $entry[2] ) );
	}
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( wpstub_hook_entries_ordered( $hook ) as $entry ) {
		$all   = array_merge( array( $value ), $args );
		$value = call_user_func_array( $entry[0], array_slice( $all, 0, $entry[2] ) );
	}
	return $value;
}

function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function register_post_meta( $type, $key, $args ) { return true; }
function register_rest_route( $ns, $route, $args ) { return true; }
function register_setting( $group, $name, $args = array() ) { return true; }

/* ------------------------------------------------------------------ i18n + escaping */

function __( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function esc_textarea( $s ) { return $s; }
function checked( $a, $b = true, $echo = true ) {}
function selected( $a, $b = true, $echo = true ) {}
function _n( $single, $plural, $number, $d = null ) {
	return 1 === (int) $number ? $single : $plural;
}

/* ------------------------------------------------------------------ sanitisers */

function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ) : ''; }
function sanitize_email( $e ) { return trim( (string) $e ); }
function is_email( $e ) { return is_string( $e ) && (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $e ) ? $e : false; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_basename( $path ) { return basename( (string) $path ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function plugin_dir_path( $f ) { return rtrim( dirname( $f ), '/' ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
function plugins_url( $path = '', $plugin = '' ) { return (string) $path; }
function wp_enqueue_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_register_style( ...$a ) {}
function wp_localize_script( ...$a ) {}
function wp_set_script_translations( ...$a ) {}
function add_menu_page( ...$a ) {}
function add_submenu_page( ...$a ) {}
function add_options_page( ...$a ) {}
function settings_fields( ...$a ) {}
function do_settings_sections( ...$a ) {}
function submit_button( ...$a ) {}
function admin_url( $p = '' ) { return (string) $p; }
function wp_create_nonce( $a = -1 ) { return 'nonce'; }
function rest_url( $p = '' ) { return (string) $p; }
function get_current_screen() { return null; }
function wp_next_scheduled( $hook, $args = array() ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_schedule_single_event( ...$a ) { return true; }
function wp_unschedule_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) { return true; }
function rest_ensure_response( $r ) { return $r; }
function rest_authorization_required_code() { return 403; }
function get_userdata( $id ) { return false; }
function wp_mail( ...$a ) { return true; }
function get_bloginfo( $k = '' ) { return 'Example'; }
function home_url( $p = '' ) { return (string) $p; }
function get_permalink( $id = 0 ) { return 'https://ex/p/' . (int) $id; }
function get_the_title( $id = 0 ) { return ''; }

/* ------------------------------------------------------------------ options */

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function add_option( $k, $v, $dep = '', $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_site_option( $k, $d = false ) { return $GLOBALS['site_options'][ $k ] ?? $d; }

/* ------------------------------------------------------------------ time */

function current_time( $type, $gmt = 0 ) { return '2026-01-01 00:00:00'; }
function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) { return $date; }

/* ------------------------------------------------------------------ caps / roles */

function current_user_can( $cap, $id = null ) { return true; }
function get_role( $role ) { return $GLOBALS['roles'][ $role ] ?? null; }

/* ------------------------------------------------------------------ posts / meta / terms */

function get_post( $id = null ) {
	$id = (int) ( $id instanceof WP_Post ? $id->ID : $id );
	return $GLOBALS['db']['posts'][ $id ] ?? null;
}
function get_post_thumbnail_id( $post_id ) { return (int) ( $GLOBALS['db']['thumbnail'][ (int) $post_id ] ?? 0 ); }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$all = $GLOBALS['db']['postmeta'][ (int) $post_id ] ?? array();
	if ( '' === $key ) {
		return $all;
	}
	if ( ! array_key_exists( $key, $all ) ) {
		return $single ? '' : array();
	}
	return $single ? $all[ $key ] : array( $all[ $key ] );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['db']['postmeta'][ (int) $post_id ][ $key ] = $value;
	return true;
}
function is_object_in_taxonomy( $post_type, $taxonomy ) {
	return in_array( $taxonomy, $GLOBALS['db']['taxonomies'][ $post_type ] ?? ( 'post' === $post_type ? array( 'category', 'post_tag' ) : array() ), true );
}
function post_type_supports( $post_type, $feature ) {
	return in_array( $feature, $GLOBALS['db']['supports'][ $post_type ] ?? array( 'editor', 'thumbnail', 'custom-fields' ), true );
}
function get_post_types( $args = array(), $output = 'names' ) {
	$types = $GLOBALS['db']['post_types'] ?? array( 'post' => (object) array( 'name' => 'post', 'label' => 'Posts', 'show_in_rest' => true, 'public' => true ), 'review' => (object) array( 'name' => 'review', 'label' => 'Reviews', 'show_in_rest' => true, 'public' => true ) );
	return $output === 'objects' ? $types : array_keys( $types );
}
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $taxonomy, $GLOBALS['db']['taxonomies'][ $post->post_type ] ?? array(), true ) ) {
		return new WP_Error( 'invalid_taxonomy' );
	}
	return $GLOBALS['db']['terms'][ (int) $post_id ][ $taxonomy ] ?? array();
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function get_term( $id, $taxonomy = '' ) { return $GLOBALS['db']['term'][ (int) $id ] ?? null; }
function get_ancestors( $term_id, $taxonomy = '', $type = 'taxonomy' ) {
	$out  = array();
	$seen = array();
	$cur  = (int) $term_id;
	while ( true ) {
		$term = $GLOBALS['db']['term'][ $cur ] ?? null;
		if ( ! $term || ! (int) $term->parent || isset( $seen[ (int) $term->parent ] ) ) {
			break;
		}
		$cur         = (int) $term->parent;
		$out[]       = $cur;
		$seen[ $cur ] = true;
	}
	return $out;
}

/* ------------------------------------------------------------------ attachments */

function wp_get_attachment_metadata( $id ) { return $GLOBALS['db']['attach_meta'][ (int) $id ] ?? false; }
function wp_get_attachment_url( $id ) { return $GLOBALS['db']['attach_url'][ (int) $id ] ?? ''; }
function get_edit_post_link( $id, $context = 'display' ) { return 'edit:' . (int) $id; }

/* ------------------------------------------------------------------ ACF (defined only when the scenario provides it) */

if ( isset( $GLOBALS['db']['acf'] ) && is_array( $GLOBALS['db']['acf'] ) ) {
	// ACF is present. (This block never runs at include time because the scenario
	// sets $GLOBALS['db']['acf'] AFTER this file loads; wpstub_enable_acf() below
	// defines the functions once the DB is built.)
}

/**
 * Define the ACF function surface. Called by the scenario builder only when ACF
 * is meant to be present, so `function_exists('acf_get_field_groups')` reports
 * absence faithfully when it is not.
 *
 * @return void
 */
function wpstub_enable_acf() {
	if ( function_exists( 'acf_get_field_groups' ) ) {
		return;
	}

	eval(
		'function acf_get_field_groups( $args = array() ) {
			$acf = $GLOBALS["db"]["acf"];
			if ( ! is_array( $acf ) ) { return array(); }
			$post_type = isset( $args["post_type"] ) ? $args["post_type"] : "";
			$cats      = isset( $args["post_terms"]["category"] ) ? (array) $args["post_terms"]["category"] : array();
			$out = array();
			foreach ( (array) ( $acf["groups"] ?? array() ) as $group ) {
				$loc = $group["location"] ?? array();
				$ok  = true;
				if ( isset( $loc["post_type"] ) && $loc["post_type"] !== $post_type ) { $ok = false; }
				if ( $ok && isset( $loc["category"] ) ) {
					$ok = in_array( (int) $loc["category"], array_map( "intval", $cats ), true );
				}
				if ( $ok ) { $out[] = $group; }
			}
			return $out;
		}'
	);
	eval(
		'function acf_get_fields( $group ) {
			return isset( $group["fields"] ) && is_array( $group["fields"] ) ? $group["fields"] : array();
		}'
	);
	eval(
		'function get_field( $selector, $post_id = false, $format = true ) {
			$acf = $GLOBALS["db"]["acf"];
			$name = $selector;
			foreach ( (array) ( $acf["groups"] ?? array() ) as $group ) {
				foreach ( (array) ( $group["fields"] ?? array() ) as $f ) {
					if ( ( $f["key"] ?? null ) === $selector ) { $name = $f["name"]; break 2; }
				}
			}
			return $acf["values"][ (int) $post_id ][ $name ] ?? null;
		}'
	);
}

/* ------------------------------------------------------------------ blocks */

function has_blocks( $content ) {
	return false !== strpos( (string) $content, '<!-- wp:' );
}

/**
 * Small fixture parser for paired and self-closing block comments, including
 * nested blocks. Not a replacement for WP_Block_Parser; unsupported malformed
 * comments and block grammar are explicitly outside this suite.
 *
 * @param string $content Post content.
 * @return array[] Blocks with blockName, attrs, innerHTML, innerBlocks.
 */
function parse_blocks( $content ) {
	$content = (string) $content;
	$blocks  = array();
	$offset  = 0;
	$len     = strlen( $content );

	$open = '/<!--\s+wp:([a-z0-9][a-z0-9_\/-]*)\s*(\{.*?\})?\s*(\/)?-->/s';

	while ( $offset < $len && preg_match( $open, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$match_start = $m[0][1];
		$match_text  = $m[0][0];

		if ( $match_start > $offset ) {
			$loose = substr( $content, $offset, $match_start - $offset );
			if ( '' !== trim( $loose ) ) {
				$blocks[] = wpstub_freeform_block( $loose );
			}
		}

		$name      = $m[1][0];
		$attrs_raw = isset( $m[2][0] ) && '' !== $m[2][0] ? $m[2][0] : '';
		$self      = isset( $m[3][0] ) && '/' === $m[3][0];
		$attrs     = '' !== $attrs_raw ? json_decode( $attrs_raw, true ) : array();
		$attrs     = is_array( $attrs ) ? $attrs : array();

		$after = $match_start + strlen( $match_text );

		if ( $self ) {
			$blocks[] = array(
				'blockName'   => 'core/' === substr( $name, 0, 5 ) || false !== strpos( $name, '/' ) ? $name : $name,
				'attrs'       => $attrs,
				'innerHTML'   => '',
				'innerBlocks' => array(),
			);
			$offset = $after;
			continue;
		}

		$close     = '<!-- /wp:' . $name . ' -->';
		$close_pos = strpos( $content, $close, $after );

		if ( false === $close_pos ) {
			// Unbalanced: treat the rest as this block's innerHTML.
			$inner  = substr( $content, $after );
			$offset = $len;
		} else {
			$inner  = substr( $content, $after, $close_pos - $after );
			$offset = $close_pos + strlen( $close );
		}

		$children = false !== strpos( $inner, '<!-- wp:' ) ? array_values( array_filter( parse_blocks( $inner ), function ( $child ) { return null !== $child['blockName']; } ) ) : array();
		// WordPress leaves child markup out of innerHTML (it lives in innerBlocks).
		// This fixture supports balanced, non-recursive child pairs.
		$blocks[] = array(
			'blockName'   => $name,
			'attrs'       => $attrs,
			'innerHTML'   => $children ? preg_replace( '/<!-- wp:([a-z0-9_\/-]+)(?:\s+\{.*?\})? -->.*?<!-- \/wp:\1 -->/s', '', $inner ) : $inner,
			'innerBlocks' => $children,
		);
	}

	if ( $offset < $len ) {
		$loose = substr( $content, $offset );
		if ( '' !== trim( $loose ) ) {
			$blocks[] = wpstub_freeform_block( $loose );
		}
	}

	return $blocks;
}

function wpstub_freeform_block( $html ) {
	return array(
		'blockName'   => null,
		'attrs'       => array(),
		'innerHTML'   => $html,
		'innerBlocks' => array(),
	);
}

/* ------------------------------------------------------------------ WP_HTML_Tag_Processor */

/**
 * Faithful-enough IMG extractor. Finds <img> tags in document order and reads
 * their attributes. Both evaluators use this one class, so even where it differs
 * from WordPress' real HTML API the two sides see the same thing.
 */
class WP_HTML_Tag_Processor {
	private $html;
	private $tags = array();
	private $cursor = -1;

	public function __construct( $html ) {
		$this->html = (string) $html;
		if ( preg_match_all( '/<img\b[^>]*>/is', $this->html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$this->tags[] = $this->parse_attrs( $tag );
			}
		}
	}

	private function parse_attrs( $tag ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', substr( $tag, 4 ), $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $pair ) {
				$key = strtolower( $pair[1] );
				if ( '' === $key || 'img' === $key ) {
					continue;
				}
				if ( ! isset( $pair[2] ) ) {
					$attrs[ $key ] = '';
					continue;
				}
				$val = $pair[2];
				if ( ( '"' === $val[0] || "'" === $val[0] ) && strlen( $val ) >= 2 ) {
					$val = substr( $val, 1, -1 );
				}
				$attrs[ $key ] = html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $attrs;
	}

	public function next_tag( $query = array() ) {
		$want = is_array( $query ) && isset( $query['tag_name'] ) ? strtoupper( $query['tag_name'] ) : null;
		if ( null !== $want && 'IMG' !== $want ) {
			return false; // only IMG is modelled, which is all the plugin asks for
		}
		if ( $this->cursor + 1 < count( $this->tags ) ) {
			$this->cursor++;
			return true;
		}
		return false;
	}

	public function get_attribute( $name ) {
		if ( $this->cursor < 0 || $this->cursor >= count( $this->tags ) ) {
			return null;
		}
		$name = strtolower( $name );
		return array_key_exists( $name, $this->tags[ $this->cursor ] ) ? $this->tags[ $this->cursor ][ $name ] : null;
	}
}
