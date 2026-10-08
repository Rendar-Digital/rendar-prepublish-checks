#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

echo '== php -l =='
bash bin/php-lint
php tests/test-updater.php

echo '== unit: boot / advisory-only / enforcement lock =='
php tests/test-advisory-only.php
php tests/test-enforcement-on.php
php tests/test-enforcement-strict.php
php tests/test-failclosed-matrix.php
php tests/test-gate-cap-slice.php
php tests/test-issue-lifecycle.php
php tests/test-cron-incomplete-issue.php
php tests/prepublish-backstop-standalone.php
php tests/test-first-published-dormant.php

echo '== non-vacuity: first-published guard bypass must fail =='
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/tests"
cp -R inc "$tmp/inc"
cp rendar-prepublish-checks.php "$tmp/"
cp tests/stubs.php tests/test-first-published-dormant.php "$tmp/tests/"
php -r '
$path = $argv[1];
$source = file_get_contents( $path );
$guard = "\tif ( get_post_meta( \$post->ID, RENDAR_PC_META_FIRST_PUBLISHED, true ) ) {\n\t\treturn;\n\t}\n";
$mutated = str_replace( $guard, "", $source, $count );
if ( 1 !== $count || false === file_put_contents( $path, $mutated ) ) {
	fwrite( STDERR, "Could not plant first-published guard bypass\\n" );
	exit( 1 );
}
' "$tmp/inc/meta.php"
if php "$tmp/tests/test-first-published-dormant.php"; then
	echo 'First-published guard bypass passed — dormant test is vacuous' >&2
	exit 1
fi
echo 'first-published guard bypass detected'

php tests/test-descriptor-validation.php
php tests/test-api-shape.php
php tests/test-applicability.php
php tests/test-fixture-public-api.php
php tests/test-save-log-setting.php
node --check assets/js/editor.js
node tests/test-editor-render.js
node tests/test-issue-watcher.js
node tests/prepublish-editor-scheduler-standalone.mjs
php tests/test-environment.php

echo
echo 'all tests passed'
