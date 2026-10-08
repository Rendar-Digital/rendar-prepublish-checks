<?php
// Updater contract. HTTP is stubbed: this test must never use the network.
const SLUG = 'rendar-prepublish-checks';
const FILE = SLUG . '/' . SLUG . '.php';
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['hooks'] = array();
$GLOBALS['cache'] = array();
$GLOBALS['calls'] = 0;
$GLOBALS['reply'] = array( 'code' => 500, 'body' => '' );

function plugin_basename( $file ) { return FILE; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ] = array( $callback, $args ); }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { add_filter( $name, $callback, $priority, $args ); }
function get_site_transient( $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['cache'][ $key ] = $value; $GLOBALS['ttl'] = $ttl; }
function delete_site_transient( $key ) { unset( $GLOBALS['cache'][ $key ] ); }
function wp_remote_get( $url, $args ) { ++$GLOBALS['calls']; $GLOBALS['request'] = array( $url, $args ); return $GLOBALS['reply']; }
function is_wp_error( $response ) { return $response instanceof WP_Error; }
class WP_Error { public function __construct( $code = '', $message = '' ) {} }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function check( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "$message\n" ); exit( 1 ); } }

if ( 'disabled' === ( $argv[1] ?? '' ) ) {
	define( 'RENDAR_UPDATES_DISABLED', true );
}

require __DIR__ . '/../inc/class-rendar-pc-updater.php';
$updater = new Rendar_PC_Updater( FILE, '0.1.0' );

if ( defined( 'RENDAR_UPDATES_DISABLED' ) ) {
	check( array() === $GLOBALS['hooks'], 'opt-out registered hooks' );
	echo "Opt-out: pass\n";
	exit;
}

check( isset( $GLOBALS['hooks']['plugins_api'], $GLOBALS['hooks']['update_plugins_updates.rendar.digital'], $GLOBALS['hooks']['upgrader_process_complete'] ), 'default path did not register updater hooks' );
check( $GLOBALS['hooks']['update_plugins_updates.rendar.digital'][1] === 4 && ! isset( $GLOBALS['hooks']['http_request_args'] ), 'native updater hook or unexpected HTTP filter' );

$package = 'https://github.com/Rendar-Digital/' . SLUG . '/releases/download/v0.2.0/' . SLUG . '.zip';
$release = array(
	'slug' => SLUG,
	'name' => '<img src=x onerror=alert(1)>',
	'version' => '0.2.0',
	'tag' => 'v0.2.0',
	'package' => $package,
	'requires' => '6.6',
	'requires_php' => '7.4',
	'tested' => '7.0.4',
	'last_updated' => '<b>2026</b>',
	'changelog' => '<script>alert(1)</script>',
);
$GLOBALS['reply'] = array( 'code' => 200, 'body' => json_encode( $release ) );
$entry = $updater->updates( false, array( 'Version' => '0.1.0' ), FILE, array( 'en_US' ) );
check( $entry['version'] === '0.2.0' && $entry['package'] === $package && $entry['slug'] === SLUG && $entry['url'] && $entry['requires'] && $entry['requires_php'] && $entry['tested'], 'incomplete native update' );
check( $GLOBALS['request'][0] === 'https://github.com/Rendar-Digital/' . SLUG . '/releases/latest/download/info.json', 'wrong release metadata URL' );
check( $GLOBALS['request'][1] === array( 'timeout' => 5, 'redirection' => 5 ), 'GitHub redirect request settings changed' );
check( 1 === $GLOBALS['calls'] && 12 * HOUR_IN_SECONDS === $GLOBALS['ttl'], 'success cache' );
check( $updater->updates( 'other', array(), 'another-plugin/file.php', array() ) === 'other', 'shared host intercepted another plugin' );
check( 1 === $GLOBALS['calls'], 'cache bypass' );

$info = $updater->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) );
check( false === strpos( $info->name, '<img' ) && false !== strpos( $info->name, '&lt;img' ), 'hostile name not escaped' );
check( false !== strpos( $info->sections['changelog'], '&lt;script&gt;' ) && false === strpos( $info->last_updated, '<b>' ), 'remote text not escaped' );
check( false === $updater->info( false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'other slug intercepted' );

$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'other/x.php' ) ) );
check( 1 === count( $GLOBALS['cache'] ), 'unrelated update cleared cache' );
$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( FILE ) ) );
check( ! $GLOBALS['cache'], 'bulk update failed to clear cache' );
$updater->updates( false, array(), FILE, array() );
$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugin' => FILE ) );
check( ! $GLOBALS['cache'], 'single update failed to clear cache' );

$invalid = array(
	array_merge( $release, array( 'slug' => 'other-plugin' ) ),
	array_merge( $release, array( 'tag' => 'v0.2.1' ) ),
	array_merge( $release, array( 'package' => 'https://example.test/' . SLUG . '.zip' ) ),
	array_merge( $release, array( 'package' => 'https://github.com/Rendar-Digital/' . SLUG . '/releases/download/v0.2.0/other.zip' ) ),
);
foreach ( $invalid as $bad ) {
	$GLOBALS['cache'] = array();
	$GLOBALS['reply'] = array( 'code' => 200, 'body' => json_encode( $bad ) );
	check( false === $updater->updates( false, array(), FILE, array() ), 'invalid release accepted' );
	check( HOUR_IN_SECONDS === $GLOBALS['ttl'], 'invalid release was not negatively cached' );
}
foreach ( array( array( 'code' => 404, 'body' => '' ), array( 'code' => 200, 'body' => '{malformed' ), new WP_Error() ) as $failure ) {
	$GLOBALS['cache'] = array();
	$GLOBALS['reply'] = $failure;
	check( false === $updater->updates( false, array(), FILE, array() ), 'failed response accepted' );
	check( HOUR_IN_SECONDS === $GLOBALS['ttl'], 'failed response was not negatively cached' );
}

$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' disabled';
exec( $command, $output, $status );
check( 0 === $status && array( 'Opt-out: pass' ) === $output, 'opt-out failed' );
echo "Updater: pass\n";
