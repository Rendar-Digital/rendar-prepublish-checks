<?php
/**
 * Public contract v1: the definitions filter validates malformed descriptors
 * safely. A bad descriptor is logged and skipped — never registered, never
 * overwriting a good check, and never emitting a PHP warning (reserved keys,
 * bad severities, non-callable callbacks, duplicate ids, non-array values).
 */
require __DIR__ . '/stubs.php';
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );

// A warning/notice must fail the test rather than pass silently.
set_error_handler(
	function ( $errno, $errstr ) {
		fwrite( STDERR, "FAIL: PHP error raised during validation: $errstr\n" );
		exit( 1 );
	}
);

$good = 'rendar_pc_check_featured_image'; // a real, callable core check.

add_filter(
	'rendar_prepublish_checks_definitions',
	function ( $checks ) use ( $good ) {
		// Reserved-key injection: a descriptor trying to set its own id to
		// something other than its array key. The key wins; 'id' is ignored.
		$checks['reserved-key'] = array( 'id' => 'something-else', 'label' => 'Reserved', 'callback' => $good );

		// Non-callable callback.
		$checks['bad-callback'] = array( 'label' => 'Bad', 'callback' => 'rendar_pc_not_a_function_xyz' );

		// Missing callback entirely.
		$checks['no-callback'] = array( 'label' => 'None' );

		// Invalid severity -> defaulted to warning, still registered.
		$checks['bad-severity'] = array( 'label' => 'Sev', 'severity' => 'catastrophic', 'callback' => $good );

		// Array where a string belongs (label/severity). Must not cast to "Array".
		$checks['array-label'] = array( 'label' => array( 'x' ), 'severity' => array( 'y' ), 'callback' => $good );

		// Non-array definition value.
		$checks['not-an-array'] = 'nonsense';

		// Empty id.
		$checks[''] = array( 'label' => 'Empty', 'callback' => $good );

		// Duplicate after sanitize: 'Dup-ID' and 'dup-id' both sanitize to 'dup-id'.
		$checks['Dup-ID'] = array( 'label' => 'First', 'callback' => $good );
		$checks['dup-id'] = array( 'label' => 'Second', 'callback' => $good );

		return $checks;
	},
	20
);

$checks = rendar_pc_get_checks();

// The good core checks plus the salvageable malformed ones registered; the
// broken ones did not.
check( isset( $checks['featured-image'] ), 'core check survives alongside malformed descriptors' );
check( isset( $checks['reserved-key'] ), 'reserved-key descriptor registered under its KEY' );
check( 'reserved-key' === $checks['reserved-key']['id'], 'descriptor cannot override its own id via a reserved key' );
check( ! isset( $checks['something-else'] ), 'the injected id never becomes a check' );

check( isset( $checks['bad-severity'] ), 'bad-severity descriptor still registered' );
check( RENDAR_PC_SEVERITY_WARNING === $checks['bad-severity']['severity'], 'invalid severity defaulted to warning (no null)' );
// Invalid stored values fall back to the validated descriptor default, not
// unconditionally to warning: a site's corrupt value must not erase policy.
$GLOBALS['options'][ RENDAR_PC_OPTION ] = array( 'severities' => array( 'bad-severity' => 'bogus', 'stored-probe' => 'bogus' ) );
check( RENDAR_PC_SEVERITY_WARNING === rendar_pc_get_severity( 'bad-severity', $checks['bad-severity']['severity'] ), 'invalid stored severity uses invalid-descriptor warning fallback' );
check( RENDAR_PC_SEVERITY_ERROR === rendar_pc_get_severity( 'stored-probe', RENDAR_PC_SEVERITY_ERROR ), 'invalid stored severity retains valid error descriptor default' );
unset( $GLOBALS['options'][ RENDAR_PC_OPTION ] );

check( isset( $checks['array-label'] ), 'array-label descriptor registered (array fields rejected, not cast)' );
check( 'array-label' === $checks['array-label']['label'], 'array label fell back to the id, not the string "Array"' );
check( '' === $checks['array-label']['description'], 'array description fell back to empty' );

check( ! isset( $checks['bad-callback'] ), 'non-callable callback rejected' );
check( ! isset( $checks['no-callback'] ), 'missing callback rejected' );
check( ! isset( $checks['not-an-array'] ), 'non-array definition rejected' );
check( ! isset( $checks[''] ), 'empty id rejected' );

check( isset( $checks['dup-id'] ), 'first of a sanitized-duplicate pair registered' );
check( 'First' === $checks['dup-id']['label'], 'duplicate keeps the FIRST, does not overwrite with the second' );

// Every malformed one is accounted for in the reject log, with a reason.
$rejected = array_column( rendar_pc_invalid_definitions(), 'reason', 'id' );
foreach ( array( 'bad-callback', 'no-callback', 'not-an-array', 'bad-severity', 'array-label', 'dup-id' ) as $id ) {
	check( isset( $rejected[ $id ] ) && '' !== $rejected[ $id ], "rejected descriptor logged with a reason: $id" );
}

// Non-vacuity: a clean registry logs nothing.
remove_all_filters_test();
rendar_pc_get_checks();
check( array() === rendar_pc_invalid_definitions(), 'a clean definitions pass logs no rejections' );

restore_error_handler();

echo "\ndescriptor-validation: OK\n";

/** Drop the test's definitions filter so the clean pass is actually clean. */
function remove_all_filters_test() {
	$GLOBALS['hooks']['rendar_prepublish_checks_definitions'] = array();
}
