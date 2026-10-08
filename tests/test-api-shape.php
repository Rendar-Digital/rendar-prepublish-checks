<?php
/**
 * Public contract v1: the engine ENFORCES the documented result and
 * descriptor shapes, coercing malformed ones safely instead of trusting a
 * third-party callback. Two halves:
 *
 *   A. rendar_pc_normalize_result() / rendar_pc_evaluate(): a callback returning
 *      an unknown status, a missing or non-scalar message, or offenders that are
 *      not a list of arrays is coerced to unavailable ("this check did not
 *      return a result") — never a silent pass, never a PHP warning, never a
 *      TypeError from array_values() on a scalar.
 *
 *   B. rendar_pc_normalize_image(): a descriptor carrying an array where a
 *      scalar belongs is discarded field-by-field at the one normalisation
 *      choke point — an array attachment_id becomes 0 (NOT 1), an array key is
 *      regenerated to a usable scalar (so the dedupe map never gets an illegal
 *      array offset), and src/alt/caption/block_name fall back to their
 *      defaults rather than casting to the literal "Array".
 *
 * Uses the in-memory WordPress stub so the checks run through real
 * rendar_pc_evaluate(), not a mock.
 *
 * Any PHP warning/notice fails the run (set_error_handler below).
 */

require __DIR__ . '/wp-stubs.php';

set_error_handler(
	function ( $errno, $errstr ) {
		fwrite( STDERR, "FAIL: PHP error raised: $errstr\n" );
		exit( 1 );
	}
);

function check( $ok, $message ) {
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
	echo '.';
}

// A single in-scope post, dated after any watermark, never published, so the
// gate decision is live and every applicable check runs.
$GLOBALS['db']['posts'][700] = new WP_Post(
	array(
		'ID'            => 700,
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_content' => '',
		'post_date'    => '2026-06-01 00:00:00',
		'post_date_gmt' => '2026-06-01 00:00:00',
	)
);

$root = dirname( __DIR__ );
require $root . '/rendar-prepublish-checks.php';
do_action( 'plugins_loaded' ); // boots the advisory core (enforcement off)

/* ============================================================ A. result shape */

add_filter(
	'rendar_prepublish_checks_definitions',
	function ( $checks ) {
		$checks['r-unknown-status'] = array(
			'label'    => 'Unknown status',
			'callback' => function () {
				return array( 'status' => 'bogus', 'message' => 'x', 'offenders' => array() );
			},
		);
		$checks['r-missing-message'] = array(
			'label'    => 'Missing message',
			'callback' => function () {
				return array( 'status' => 'fail', 'offenders' => array() );
			},
		);
		$checks['r-nonscalar-message'] = array(
			'label'    => 'Non-scalar message',
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => array( 'nope' ), 'offenders' => array() );
			},
		);
		$checks['r-scalar-offenders'] = array(
			'label'    => 'Scalar offenders',
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => 'x', 'offenders' => 'not-a-list' );
			},
		);
		$checks['r-nonarray-offender'] = array(
			'label'    => 'Non-array offender entry',
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => 'x', 'offenders' => array( 'bad' ) );
			},
		);
		foreach ( array( 'key', 'label' ) as $field ) {
			$checks[ 'r-invalid-offender-' . $field ] = array(
				'callback' => function () use ( $field ) {
					return array( 'status' => 'fail', 'message' => 'x', 'offenders' => array( array_merge( array( 'key' => 'x:1', 'label' => 'X' ), array( $field => array( 'bad' ) ) ) ) );
				},
			);
		}
		$checks['r-null-offender-label'] = array(
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => 'x', 'offenders' => array( array( 'key' => 'x:1', 'label' => null ) ) );
			},
		);
		$checks['r-null-offender-edit-url'] = array(
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => 'x', 'offenders' => array( array( 'key' => 'x:1', 'edit_url' => null ) ) );
			},
		);
		$checks['r-invalid-offender-edit-url'] = array(
			'label'    => 'Invalid offender edit URL',
			'callback' => function () {
				return array( 'status' => 'fail', 'message' => 'x', 'offenders' => array( array( 'key' => 'x:1', 'label' => 'X', 'edit_url' => array( 'bad' ) ) ) );
			},
		);
		$checks['r-not-even-array'] = array(
			'label'    => 'Returns a string',
			'callback' => function () {
				return 'totally wrong';
			},
		);
		$checks['r-valid-fail'] = array(
			'label'    => 'Valid fail (control)',
			'severity' => RENDAR_PC_SEVERITY_ERROR,
			'callback' => function () {
				return array(
					'status'    => 'fail',
					'message'   => 'a real failure',
					'offenders' => array( array( 'key' => 'x:1', 'label' => 'X', 'id' => 12, 'src' => 'https://example.test/x.jpg', 'edit_url' => '/edit/12' ) ),
				);
			},
		);

		return $checks;
	},
	50
);

$report = rendar_pc_evaluate( Rendar_PC_Context::build( 700, array( 'post_status' => 'publish' ) ) );

$by_id = array();
foreach ( $report['checks'] as $c ) {
	$by_id[ $c['id'] ] = $c;
}

$na_message = 'This check did not return a valid result.';

foreach ( array(
	'r-unknown-status',
	'r-missing-message',
	'r-nonscalar-message',
	'r-scalar-offenders',
	'r-nonarray-offender',
	'r-invalid-offender-key',
	'r-invalid-offender-label',
	'r-null-offender-label',
	'r-null-offender-edit-url',
	'r-invalid-offender-edit-url',
	'r-not-even-array',
) as $id ) {
	check( isset( $by_id[ $id ] ), "malformed-result check present in report: $id" );
	check( 'unavailable' === $by_id[ $id ]['status'], "malformed result coerced to unavailable: $id" );
	check( $na_message === $by_id[ $id ]['message'], "coerced result carries the honest message: $id" );
	check( array() === $by_id[ $id ]['offenders'], "coerced result has empty offenders: $id" );
}

// These descriptors default to warning: unavailable stays advisory and is
// never misreported as a failed check.
foreach ( array( 'r-unknown-status', 'r-scalar-offenders', 'r-nonarray-offender' ) as $id ) {
	check( ! in_array( $id, $report['failing_errors'], true ) && ! in_array( $id, $report['blocking_ids'], true ), "warning malformed result remains advisory: $id" );
}

// The valid control passes through untouched: status, message, offenders intact.
check( isset( $by_id['r-valid-fail'] ), 'valid control check present' );
check( 'fail' === $by_id['r-valid-fail']['status'], 'valid fail stays fail' );
check( 'a real failure' === $by_id['r-valid-fail']['message'], 'valid message preserved' );
check( array( array( 'key' => 'x:1', 'label' => 'X', 'id' => 12, 'src' => 'https://example.test/x.jpg', 'edit_url' => '/edit/12' ) ) === $by_id['r-valid-fail']['offenders'], 'valid offenders preserved' );
check( in_array( 'r-valid-fail', $report['failing_errors'], true ), 'valid error-severity fail still gates' );

// A valid offender without the optional label receives its safe, meaningful
// fallback rather than handing undefined to every report consumer.
$label_fallback = rendar_pc_normalize_result( array(
	'status' => 'fail',
	'message' => 'x',
	'offenders' => array( array( 'key' => 'x:2' ) ),
) );
check( 'x:2' === $label_fallback['offenders'][0]['label'], 'missing optional offender label falls back to its key' );

// Direct unit assertions on the normaliser, independent of evaluate().
check( 'unavailable' === rendar_pc_normalize_result( null )['status'], 'normalize_result(null) -> unavailable' );
check( 'unavailable' === rendar_pc_normalize_result( 42 )['status'], 'normalize_result(scalar) -> unavailable' );
check( 'pass' === rendar_pc_normalize_result( rendar_pc_pass( 'ok' ) )['status'], 'normalize_result passes a valid result' );

/* ======================================================= B. descriptor shape */

// array attachment_id must become 0, not 1.
$d = rendar_pc_normalize_image(
	array( 'attachment_id' => array( 5, 6 ), 'src' => 'https://ex/x.jpg', 'alt' => 'a' ),
	'core/image',
	array()
);
check( 0 === $d['attachment_id'], 'array attachment_id -> 0 (not 1)' );

// array src / alt / caption fall back to empty, never the string "Array".
$d = rendar_pc_normalize_image(
	array( 'src' => array( 'x' ), 'alt' => array( 'y' ), 'caption' => array( 'z' ) ),
	'core/image',
	array()
);
check( '' === $d['src'], 'array src -> empty string' );
check( '' === $d['alt'], 'array alt -> empty string' );
check( '' === $d['caption'], 'array caption -> empty string' );

// array block_name falls back to the passed block name.
$d = rendar_pc_normalize_image(
	array( 'src' => 'https://ex/x.jpg', 'block_name' => array( 'oops' ) ),
	'my/block',
	array()
);
check( 'my/block' === $d['block_name'], 'array block_name -> falls back to the real block name' );

// array key: regenerated to a usable scalar, and safe as a dedupe-map offset.
$d = rendar_pc_normalize_image(
	array( 'attachment_id' => 9, 'src' => 'https://ex/x.jpg', 'key' => array( 'bad', 'key' ) ),
	'core/image',
	array()
);
check( is_string( $d['key'] ), 'array key -> a string key' );
check( 'id:9' === $d['key'], 'array key regenerated from the attachment id' );

// Decorative is an acknowledgment, not a truthiness conversion. Malformed
// values must never make an image look deliberately decorative.
foreach ( array( array( true ), (object) array( 'true' => true ), 'false', 1 ) as $malformed_decorative ) {
	$d = rendar_pc_normalize_image(
		array( 'src' => 'https://ex/x.jpg', 'markup_decorative' => $malformed_decorative ),
		'core/image',
		array()
	);
	check( false === $d['markup_decorative'], 'malformed markup_decorative is false' );
}
$d = rendar_pc_normalize_image( array( 'src' => 'https://ex/x.jpg', 'markup_decorative' => true ), 'core/image', array() );
check( true === $d['markup_decorative'], 'boolean true remains decorative' );
$d = rendar_pc_normalize_image( array( 'src' => 'https://ex/x.jpg', 'markup_decorative' => false ), 'core/image', array() );
check( false === $d['markup_decorative'], 'boolean false remains non-decorative' );

// The illegal-offset proof: run a descriptor with an array key all the way
// through extract -> dedupe (which uses $image[key] as an array key) with no
// TypeError. The filter injects the malformed descriptor for a known block.
add_filter(
	'rendar_prepublish_checks_block_images',
	function ( $images, $block ) {
		if ( 'my/evil' !== ( $block['blockName'] ?? '' ) ) {
			return $images;
		}
		return array(
			array( 'attachment_id' => array( 1 ), 'src' => array( 'x' ), 'alt' => array( 'y' ), 'key' => array( 'k' ), 'block_name' => array( 'b' ) ),
			array( 'attachment_id' => 11, 'src' => 'https://ex/ok.jpg', 'alt' => 'ok' ),
		);
	},
	10,
	2
);

$extracted = rendar_pc_extract_images( '<!-- wp:my/evil --><p>x</p><!-- /wp:my/evil -->' );
check( is_array( $extracted ), 'extract with a fully-array descriptor returns an array (no TypeError in dedupe)' );
foreach ( $extracted as $img ) {
	check( is_string( $img['key'] ) && '' !== $img['key'], 'every extracted image has a usable string key' );
	check( is_int( $img['attachment_id'] ), 'every extracted attachment_id is an int' );
}

restore_error_handler();

echo "\napi-shape: OK\n";
