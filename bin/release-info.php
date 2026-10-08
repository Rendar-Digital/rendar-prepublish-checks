<?php
// Validate a release tag against committed plugin metadata; emit info.json only on success.
$slug = 'rendar-prepublish-checks';
$root = __DIR__ . '/..';
$source = file_get_contents( $root . '/' . $slug . '.php' );
$changelog = file_get_contents( $root . '/CHANGELOG.md' );
$tag = $argv[1] ?? '';
$fields = array(
	'name' => 'Plugin Name', 'version' => 'Version', 'requires' => 'Requires at least',
	'requires_php' => 'Requires PHP', 'tested' => 'Tested up to',
);
$info = array( 'slug' => $slug );
foreach ( $fields as $key => $header ) {
	if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+?)\s*$/m', $source, $match ) ) {
		fwrite( STDERR, "Missing header: $header\n" ); exit( 1 );
	}
	$info[ $key ] = $match[1];
}
if ( ! preg_match( '/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $tag ) || $tag !== 'v' . $info['version']
	|| ! preg_match( "/define\\( 'RENDAR_PC_VERSION', '([^']+)' \\)/", $source, $match )
	|| $match[1] !== $info['version'] ) {
	fwrite( STDERR, "Tag, header and version constant must agree on a stable X.Y.Z release\n" ); exit( 1 );
}
foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
	if ( ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $info[ $key ] ) ) {
		fwrite( STDERR, "Invalid $key\n" ); exit( 1 );
	}
}
if ( ! preg_match( '/^##\s+' . preg_quote( $info['version'], '/' ) . '(?:\s|$).*?\R(.*?)(?=^##\s|\z)/ms', $changelog, $match ) ) {
	fwrite( STDERR, "Missing CHANGELOG.md section for {$info['version']}\n" ); exit( 1 );
}
$section = trim( $match[1] );
if ( '' === $section ) {
	fwrite( STDERR, "Empty CHANGELOG.md section for {$info['version']}\n" ); exit( 1 );
}
$info['tag'] = $tag;
$info['package'] = 'https://github.com/Rendar-Digital/' . $slug . '/releases/download/' . $tag . '/' . $slug . '.zip';
$info['last_updated'] = gmdate( 'Y-m-d H:i:s' );
$info['changelog'] = $section;
$dist = $root . '/dist';
if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) ) {
	fwrite( STDERR, "Could not create dist directory\n" ); exit( 1 );
}
if ( false === file_put_contents( $dist . '/info.json', json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
	fwrite( STDERR, "Could not write dist/info.json\n" ); exit( 1 );
}
