<?php
/** Guard the fixture against private core function and constant dependencies. */

/**
 * Return every private rendar_pc_* symbol a fixture source references.
 *
 * Functions are allowed only when listed in the public v1 contract. Any
 * non-call reference to the prefix is a constant and is forbidden regardless
 * of its spelling case; PHP tokenizes both RENDAR_PC_FOO and Rendar_Pc_Foo as
 * T_STRING.
 *
 * @param string $source PHP source.
 * @return string[] Symbol names and source lines.
 */
function fixture_public_api_violations( $source ) {
	$allowed_functions = array(
		'rendar_pc_pass', 'rendar_pc_fail', 'rendar_pc_not_applicable',
		'rendar_pc_unavailable', 'rendar_pc_image_offender',
		'rendar_pc_caption_is_blank', 'rendar_pc_classic_skip_message',
	);
	$allowed_symbols = array( 'rendar_pc_context' );
	$tokens     = token_get_all( $source );
	$violations = array();

	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}

		$name = $token[1];
		if ( 0 !== strpos( strtolower( $name ), 'rendar_pc_' ) ) {
			continue;
		}

		$next_index = $index + 1;
		while ( isset( $tokens[ $next_index ] ) && is_array( $tokens[ $next_index ] ) && T_WHITESPACE === $tokens[ $next_index ][0] ) {
			++$next_index;
		}
		$next = $tokens[ $next_index ] ?? null;

		$normalized_name = strtolower( $name );
		if ( '(' === $next && in_array( $normalized_name, $allowed_functions, true ) ) {
			continue;
		}
		if ( in_array( $normalized_name, $allowed_symbols, true ) ) {
			continue;
		}

		$violations[] = $name . ':' . $token[2];
	}

	return $violations;
}

$source     = file_get_contents( __DIR__ . '/fixtures/example-policy/example-policy.php' );
$violations = fixture_public_api_violations( $source );
if ( $violations ) {
	fwrite( STDERR, 'Fixture uses undocumented core symbols: ' . implode( ', ', $violations ) . "\n" );
	exit( 1 );
}

// Negative control: a mixed-case private constant must make this guard fail.
// This makes the scanner's case-insensitive constant branch demonstrable,
// rather than merely asserting that the current fixture happens to be clean.
$seeded = fixture_public_api_violations( "<?php\nRendar_Pc_Severity_Error;\n" );
if ( ! $seeded || false === strpos( $seeded[0], 'Rendar_Pc_Severity_Error' ) ) {
	fwrite( STDERR, "Fixture public-API negative control was not detected\n" );
	exit( 1 );
}

echo "fixture public API: ok\n";
