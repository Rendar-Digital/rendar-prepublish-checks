<?php
/** Exercise the fixture's Settings API registration and form submission path. */
require __DIR__ . '/stubs.php';
$GLOBALS['settings_registration'] = array();
function register_setting( $group, $name, $args ) {
	$GLOBALS['settings_registration'][ $name ] = array( $group, $args );
}
function checked( $value ) { echo $value ? 'checked="checked"' : ''; }
function esc_html_e( $text, $domain = '' ) { echo $text; }
require __DIR__ . '/fixtures/example-policy/example-policy.php';
fire_hook( 'admin_init' );
$registration = $GLOBALS['settings_registration'][ EXAMPLE_PP_SAVE_LOG_OPTION ] ?? null;
check( $registration && 'rendar_pc' === $registration[0], 'Save Log must register under form group' );
check( '1' === $registration[1]['default'], 'Save Log default on' );
$sanitize = $registration[1]['sanitize_callback'];
check( is_callable( $sanitize ), 'Save Log sanitizer callable' );
foreach ( array( '1' => '1', '0' => '0', 'bogus' => '0' ) as $input => $expected ) {
	// Simulates options.php invoking the registered sanitizer before saving.
	$GLOBALS['options'][ EXAMPLE_PP_SAVE_LOG_OPTION ] = $sanitize( $input );
	check( $expected === get_option( EXAMPLE_PP_SAVE_LOG_OPTION ), 'saved option roundtrip: ' . $input );
}
ob_start();
fire_hook( 'rendar_prepublish_checks_settings_sections' );
$html = ob_get_clean();
check( false !== strpos( $html, 'type="hidden" value="0" name="example_save_log_cron_status"' ), 'unchecked checkbox submits zero' );
check( false !== strpos( $html, 'type="checkbox" value="1" name="example_save_log_cron_status"' ), 'checked checkbox submits one' );
echo "\nsave-log-setting: OK\n";
