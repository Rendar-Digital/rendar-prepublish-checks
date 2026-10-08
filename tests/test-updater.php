<?php
// Standalone updater contract, against a real WordPress core's HTTP header parser and Requests IRI.
const SLUG = 'rendar-prepublish-checks';
const FILE = SLUG . '/' . SLUG . '.php';

// WP_CORE_DIR must point at a WordPress installation root (contains wp-admin/, wp-includes/,
// wp-load.php) -- not at wp-includes/ itself. CI provisions the version pinned in
// bin/provision-wp-core (see docs/wp-core-pin.md) and exports WP_CORE_DIR before this file runs;
// CI must hard-fail rather than silently skip if it is missing. Locally, point it at any WP
// checkout, e.g. `WP_CORE_DIR=/path/to/wordpress php tests/test-updater.php`, or run
// `bin/provision-wp-core` to fetch the pinned version. Leaving it unset locally skips this file
// with a clear message instead of failing on a developer-machine-specific path.
$wp_core_root = getenv( 'WP_CORE_DIR' );
if ( false === $wp_core_root || '' === trim( $wp_core_root ) ) {
    if ( getenv( 'CI' ) ) {
        fwrite( STDERR, "WP_CORE_DIR is not set. CI must provision a pinned WordPress core (bin/provision-wp-core) and export WP_CORE_DIR before running tests/test-updater.php.\n" );
        exit( 1 );
    }
    fwrite( STDERR, "SKIP: WP_CORE_DIR is not set; skipping tests/test-updater.php.\n" .
        "Point it at a WordPress installation root (contains wp-includes/), e.g.:\n" .
        "  WP_CORE_DIR=/path/to/wordpress php tests/test-updater.php\n" .
        "Or run bin/provision-wp-core to download the version CI pins; see docs/wp-core-pin.md.\n" );
    exit( 0 );
}
define( 'CORE', rtrim( $wp_core_root, '/' ) . '/wp-includes/' );
define( 'ABSPATH', dirname( CORE ) . '/' );
define( 'WPINC', 'wp-includes' );
require CORE . 'class-wp-http.php';
$GLOBALS['hooks'] = array(); $GLOBALS['cache'] = array(); $GLOBALS['calls'] = 0;
function plugin_basename( $file ) { return FILE; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$name] = array( $callback, $args ); }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { add_filter( $name, $callback, $priority, $args ); }
function get_site_transient( $key ) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient( $key, $value, $ttl ) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttl'] = $ttl; }
function delete_site_transient( $key ) { unset( $GLOBALS['cache'][$key] ); }
function wp_remote_get( $url, $args ) { ++$GLOBALS['calls']; $GLOBALS['request'] = array( $url, $args ); return $GLOBALS['reply']; }
function is_wp_error( $response ) { return $response instanceof WP_Error; }
class WP_Error { public function __construct( $code = '', $message = '' ) {} }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function check( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "$message\n" ); exit( 1 ); } }
require __DIR__ . '/../inc/class-rendar-pc-updater.php';
if ( 'unconfigured' === ( $argv[1] ?? '' ) ) {
    $unconfigured = new Rendar_PC_Updater( FILE, '0.1.0' );
    check( array_keys( $GLOBALS['hooks'] ) === array( 'plugins_api' ), 'unconfigured must register only info hook' );
    check( $unconfigured->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) ) instanceof WP_Error, 'unconfigured own slug must not fall through' );
    check( 0 === $GLOBALS['calls'], 'unconfigured network request' );
    echo "Unconfigured: pass\n"; exit;
}
if ( 'bad-base' === ( $argv[1] ?? '' ) ) {
    define( 'RENDAR_UPDATES_URL', $argv[2] );
    define( 'RENDAR_UPDATES_TOKEN', 'test-placeholder' );
    $bad = new Rendar_PC_Updater( FILE, '0.1.0' );
    check( ! isset( $GLOBALS['hooks']['http_request_args'] ) && $bad->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) ) instanceof WP_Error, 'ambiguous base accepted' );
    exit;
}
foreach ( array( 'https://updates.example.test/proxy ', "https://updates.example.test/proxy\t", "https://updates.example.test/proxy\n", 'https://updates.example.test/proxy/..%20' ) as $bad_base ) {
    $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' bad-base ' . escapeshellarg( $bad_base );
    exec( $command, $output, $status );
    check( 0 === $status, 'unsafe base accepted' );
}
define( 'RENDAR_UPDATES_URL', 'https://updates.example.test/proxy' );
define( 'RENDAR_UPDATES_TOKEN', 'test-placeholder' );
define( 'HOUR_IN_SECONDS', 3600 );
$updater = new Rendar_PC_Updater( FILE, '0.1.0' );
check( $GLOBALS['hooks']['update_plugins_updates.rendar.digital'][1] === 4 && ! isset( $GLOBALS['hooks']['pre_set_site_transient_update_plugins'] ), 'native four-argument hook required' );
$base = RENDAR_UPDATES_URL;
$release = array( 'slug' => SLUG, 'name' => '<img src=x onerror=alert(1)>', 'version' => '0.2.0', 'tag' => 'v0.2.0',
    'package' => "$base/v1/download/" . SLUG . '/v0.2.0', 'requires' => '6.6',
    'requires_php' => '7.4', 'tested' => '7.0.4', 'last_updated' => '<b>2026</b>',
    'changelog' => '<script>alert(1)</script>' );
$GLOBALS['reply'] = array( 'code' => 200, 'body' => json_encode( $release ) );
$entry = $updater->updates( false, array( 'Version' => '0.1.0' ), FILE, array( 'en_US' ) );
check( $entry['version'] === '0.2.0' && $entry['package'] === $release['package'] && $entry['slug'] === SLUG && $entry['url'] && $entry['requires'] && $entry['requires_php'] && $entry['tested'], 'incomplete native update' );
check( $updater->updates( 'other', array(), 'another-plugin/file.php', array() ) === 'other', 'shared host intercepts another plugin' );
check( $GLOBALS['calls'] === 1 && $GLOBALS['ttl'] === 21600, 'success cache' );
// Core update.php compares the live plugin_data Version after invoking the hostname filter.
foreach ( array( '0.1.0' => true, '0.3.0' => false ) as $installed => $expected ) {
    check( ( version_compare( $entry['version'], $installed, '>' ) ) === $expected, 'live version comparison' );
}
$info = $updater->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) );
check( ! str_contains( $info->name, '<img' ) && str_contains( $info->name, '&lt;img' ), 'hostile name not escaped' );
check( str_contains( $info->sections['changelog'], '&lt;script&gt;' ) && ! str_contains( $info->last_updated, '<b>' ), 'remote text not escaped' );
check( false === $updater->info( false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'other slug intercepted' );
check( $GLOBALS['calls'] === 1, 'cache bypass' );
$headers = $updater->authorize( array( 'headers' => "X-Test: retained\r\nauthorization: old", 'redirection' => 5 ), $release['package'] );
check( $headers['headers'] === array( 'x-test' => 'retained', 'Authorization' => 'Bearer test-placeholder' ) && $headers['redirection'] === 0, 'string headers/redirect handling' );
$headers = $updater->authorize( array( 'headers' => array( 'AUTHORIZATION' => 'old', 'Keep' => 'yes' ) ), $release['package'] );
check( $headers['headers'] === array( 'Keep' => 'yes', 'Authorization' => 'Bearer test-placeholder' ), 'array headers case-insensitive replacement' );
foreach ( array( 'http://updates.example.test/proxy/v1/download/x', 'https://updates.example.test.evil.com/proxy/v1/x',
    'https://updates.example.test/proxy/v1evil/x', 'https://updates.example.test/other/v1/x',
    'https://other.example.test/proxy/v1/x', "$base/v1/../outside", "$base/v1/.. /outside",
    "$base/v1/..\t/outside", "$base/v1/../outside ", "$base/v1/%2e%2e/outside",
    "$base/v1/%2e%2e%20/outside", "$base/v1/%2Foutside", "$base/v1//outside" ) as $url ) {
    check( ! isset( $updater->authorize( array( 'headers' => array() ), $url )['headers']['Authorization'] ), "bearer leaked to $url" );
}
$iri = \WpOrg\Requests\Iri::absolutize( 'https://updates.example.test/', '/proxy/v1/../outside' );
check( $iri->path === '/proxy/outside', 'real Requests IRI dot-segment behavior changed' );
$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'other/x.php' ) ) );
check( count( $GLOBALS['cache'] ) === 1, 'unrelated update cleared cache' );
$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( FILE ) ) );
check( ! $GLOBALS['cache'], 'bulk update failed to clear cache' );
$updater->updates( false, array(), FILE, array() );
$updater->clear( null, array( 'type' => 'plugin', 'action' => 'update', 'plugin' => FILE ) );
check( ! $GLOBALS['cache'], 'single update failed to clear cache' );
foreach ( array( array_merge( $release, array( 'version' => 'banana' ) ),
    array_merge( $release, array( 'package' => 'https://updates.example.test.evil.com/proxy/v1/x' ) ) ) as $bad ) {
    $GLOBALS['cache'] = array(); $GLOBALS['reply'] = array( 'code' => 200, 'body' => json_encode( $bad ) );
    check( false === $updater->updates( false, array(), FILE, array() ), 'invalid release accepted' );
    check( $GLOBALS['ttl'] === 3600 && $updater->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) ) instanceof WP_Error, 'invalid/negative cache fallback' );
}
foreach ( array( array( 'code' => 404, 'body' => '' ), new WP_Error() ) as $error ) {
    $GLOBALS['cache'] = array(); $GLOBALS['reply'] = $error;
    check( false === $updater->updates( false, array(), FILE, array() ), 'failure accepted' );
    check( $GLOBALS['ttl'] === 3600 && $updater->info( false, 'plugin_information', (object) array( 'slug' => SLUG ) ) instanceof WP_Error, 'failure fallback' );
}
echo "Updater: pass\n";
