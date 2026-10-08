#!/usr/bin/env bash
# Opt-in, local-only, single-writer integration runner. Never pass a site path or URL.
# Requires RPC_SQLITE_PLUGIN_DIR (see tests/integration/README.md).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
VER=6.8.3
if [ "$#" -gt 0 ]; then [ "$#" = 2 ] && [ "$1" = --wp ] || { echo 'usage: run.sh [--wp X.Y.Z]' >&2; exit 2; }; VER="$2"; fi
R="$ROOT/tests/.runtime"; W="$R/wp-$VER"
G() { if [ "$#" -eq 0 ]; then python3 "$ROOT/tests/integration/guard.py" "$ROOT" "$VER"; else python3 "$ROOT/tests/integration/guard.py" "$ROOT" "$VER" "$1"; fi; }
G # Preflight BEFORE the first writer, including mkdir.
python3 "$ROOT/tests/integration/guard.py" "$ROOT" "$VER" --source-clean
mkdir -p "$R"
G
# The SQLite Database Integration plugin (https://github.com/WordPress/sqlite-database-integration)
# is read-only input, copied into the fixture. No default: it must be named explicitly.
SQLITE="${RPC_SQLITE_PLUGIN_DIR:-}"
[ -n "$SQLITE" ] || { echo 'Set RPC_SQLITE_PLUGIN_DIR to a local checkout of sqlite-database-integration' >&2; exit 1; }
[ -f "$SQLITE/db.copy" ] || { echo "RPC_SQLITE_PLUGIN_DIR has no db.copy: $SQLITE" >&2; exit 1; }
PID=''
AUTH=''
cleanup() {
  [ -z "$PID" ] || { kill "$PID" 2>/dev/null || true; wait "$PID" 2>/dev/null || true; }
  if G; then
    if [ -d "$W" ]; then rm -rf -- "$W"; fi
    rm -f -- "$R/http-code" "$R/server.log" "$R/server.pid"
  fi
  if [ -n "$AUTH" ]; then rm -f -- "$AUTH/cookies"; rmdir -- "$AUTH"; fi
}
trap cleanup EXIT
if [ -e "$W" ]; then rm -rf -- "$W"; fi
umask 077
AUTH="$(mktemp -d /tmp/rpc-auth.XXXXXXXX)"
mkdir -p "$W"; printf 'rpc-disposable-fixture\n' > "$W/.rpc-fixture"
G
PORT=''
for _ in $(seq 1 60); do p=$((40000 + RANDOM % 20000)); if ! lsof -nP -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1; then PORT="$p"; break; fi; done
[ -n "$PORT" ] || exit 1
URL="http://127.0.0.1:$PORT"
wp --path="$W" core download --version="$VER" --force --quiet >/dev/null 2>&1
wp --path="$W" config create --dbname=rpc_scratch --dbuser=rpc --dbpass=rpc --dbhost=127.0.0.1 --skip-check --force --extra-php <<'PHP' >/dev/null
define('WP_ENVIRONMENT_TYPE', 'local');
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_DEBUG_DISPLAY', false);
PHP
mkdir -p "$W/wp-content/mu-plugins"
cp -R "$SQLITE" "$W/wp-content/mu-plugins/sqlite-database-integration"
sed "s|{SQLITE_IMPLEMENTATION_FOLDER_PATH}|$W/wp-content/mu-plugins/sqlite-database-integration|g; s|{SQLITE_PLUGIN}|sqlite-database-integration/load.php|g" "$SQLITE/db.copy" > "$W/wp-content/db.php"
PASS="$(openssl rand -hex 24)"
wp --path="$W" core install --url="$URL" --title='RPC synthetic fixture' --admin_user=rpc_admin --admin_password="$PASS" --admin_email=admin@example.invalid --skip-email >/dev/null
cat > "$W/wp-content/mu-plugins/rpc-fixture.php" <<'PHP'
<?php
add_action('init', function () {
    register_post_type('portfolio', ['label'=>'Portfolio','public'=>true,'show_in_rest'=>true,'supports'=>['title','editor','custom-fields']]);
    register_post_type('memo', ['label'=>'Memo','public'=>true,'show_in_rest'=>true,'supports'=>['title','editor']]);
    register_taxonomy_for_object_type('category', 'portfolio');
}, 100);
PHP
# Copy only the shipping allowlist; the HTTP server must never see this checkout.
PLUGIN="$W/wp-content/plugins/rendar-prepublish-checks"
mkdir -p "$PLUGIN"
while IFS= read -r -d '' item; do
  case "$item" in rendar-prepublish-checks.php|README.md|CHANGELOG.md|inc/*|assets/*) ;; *) continue ;; esac
  [ -f "$ROOT/$item" ] && [ ! -L "$ROOT/$item" ] || { echo 'invalid shipping file' >&2; exit 1; }
  parent="$(dirname "$item")"
  while [ "$parent" != . ]; do
    [ ! -L "$ROOT/$parent" ] || { echo 'linked shipping directory' >&2; exit 1; }
    parent="$(dirname "$parent")"
  done
  mkdir -p "$PLUGIN/$(dirname "$item")"
  cp -- "$ROOT/$item" "$PLUGIN/$item"
done < <(git -C "$ROOT" ls-files -z)
[ -f "$PLUGIN/rendar-prepublish-checks.php" ] || exit 1
wp --path="$W" plugin activate rendar-prepublish-checks --quiet
wp --path="$W" user create rpc_author author@example.invalid --role=author --quiet >/dev/null
wp --path="$W" user create rpc_editor editor@example.invalid --role=editor --quiet >/dev/null
A="$(wp --path="$W" user application-password create rpc_admin fixture --porcelain)"
E="$(wp --path="$W" user application-password create rpc_editor fixture --porcelain)"
U="$(wp --path="$W" user application-password create rpc_author fixture --porcelain)"
TOKEN="$(openssl rand -hex 24)"
cat > "$W/wp-content/mu-plugins/rpc-ready.php" <<PHP
<?php
add_action('init', function () {
    if (\$_SERVER['REQUEST_URI'] === '/rpc-fixture-ready') {
        header('Content-Type: text/plain');
        echo '$TOKEN';
        exit;
    }
});
PHP
php -S "127.0.0.1:$PORT" -t "$W" > "$R/server.log" 2>&1 & PID=$!
READY=0
for _ in $(seq 1 50); do
  kill -0 "$PID" 2>/dev/null || { echo 'server exited' >&2; exit 1; }
  if lsof -nP -a -p "$PID" -iTCP:"$PORT" -sTCP:LISTEN 2>/dev/null | grep -q "127.0.0.1:$PORT" &&
     [ "$(curl --max-time 2 -fsS "$URL/rpc-fixture-ready" 2>/dev/null || true)" = "$TOKEN" ]; then READY=1; break; fi
  sleep .2
done
[ "$READY" = 1 ] || { echo 'fixture server not ready/owned' >&2; exit 1; }
G "$URL"
: > "$R/results-$VER.jsonl"
N=0
check() { N=$((N+1)); if [ "$2" != "$3" ]; then echo "FAIL $1 expected $2 got $3" >&2; exit 1; fi; jq -cn --arg name "$1" '{name:$name,status:"pass"}' >> "$R/results-$VER.jsonl"; }
http() { G "$URL"; curl -sS -o /dev/null -w '%{http_code}' "$URL$1" "${@:2}"; }
route='/index.php?rest_route=/rendar-prepublish-checks/v1/evaluate'
data='{"post_id":0,"post_type":"post","status":"publish","content":"<!-- wp:paragraph --><p>synthetic</p><!-- /wp:paragraph -->"}'
check 'anonymous evaluate denied' 401 "$(http "$route" -H 'Content-Type: application/json' -d "$data")"
check 'author evaluate allowed' 200 "$(http "$route" -u "rpc_author:$U" -H 'Content-Type: application/json' -d "$data")"
check 'editor evaluate allowed' 200 "$(http "$route" -u "rpc_editor:$E" -H 'Content-Type: application/json' -d "$data")"
check 'admin evaluate allowed' 200 "$(http "$route" -u "rpc_admin:$A" -H 'Content-Type: application/json' -d "$data")"
# REST response content, not just HTTP status. No auth or response bodies persisted.
report="$(curl -fsS -u "rpc_admin:$A" -H 'Content-Type: application/json' -d "$data" "$URL$route")"
check 'advisory verdict' false "$(jq -r '.would_block' <<< "$report")"
check 'override unavailable' false "$(jq -r '.can_override' <<< "$report")"
check 'issue route absent' 404 "$(http '/index.php?rest_route=/rendar-prepublish-checks/v1/issue/1' -u "rpc_admin:$A")"
# Real options.php: cookie login + form nonce, not direct update_option() for the save.
G "$URL"
curl -fsS -c "$AUTH/cookies" -b "$AUTH/cookies" -o /dev/null -d "log=rpc_admin&pwd=$PASS&wp-submit=Log+In&testcookie=1" "$URL/wp-login.php"
[ -s "$AUTH/cookies" ] && [ "$(stat -f %Lp "$AUTH")" = 700 ] && [ "$(stat -f %Lp "$AUTH/cookies")" = 600 ] || { echo 'unsafe auth file permissions' >&2; exit 1; }
# Check exposure with a real auth jar already present, not before login.
check 'checkout cookie URL inaccessible' 404 "$(http '/wp-content/plugins/rendar-prepublish-checks/tests/.runtime/cookies')"
check 'docroot cookie URL inaccessible' 404 "$(http '/tests/.runtime/cookies')"
form="$(curl -fsS -b "$AUTH/cookies" "$URL/wp-admin/options-general.php?page=rendar-pc")"
nonce="$(printf '%s' "$form" | python3 -c 'import re,sys,html; m=re.search(r"name=[\"\x27]_wpnonce[\"\x27] value=[\"\x27]([^\"\x27]+)",sys.stdin.read()); print(html.unescape(m.group(1)) if m else "")')"
[ -n "$nonce" ] || { echo 'settings nonce not found' >&2; exit 1; }
option() { wp --path="$W" option get rendar_pc_settings --format=json | jq -c '.post_types'; }
check 'invalid nonce rejected' 403 "$(http '/wp-admin/options.php' -b "$AUTH/cookies" -d 'option_page=rendar_pc&action=update&_wpnonce=invalid' --data-urlencode 'rendar_pc_settings[post_types][]=portfolio')"
check 'invalid nonce unchanged' '["post"]' "$(wp --path="$W" eval 'echo wp_json_encode(rendar_pc_get_post_types());')"
# settings form includes hidden empty value, plus forged unsupported CPT; only eligible types persist.
http '/wp-admin/options.php' -b "$AUTH/cookies" -d "option_page=rendar_pc&action=update&_wpnonce=$nonce" --data-urlencode 'rendar_pc_settings[post_types][]=' --data-urlencode 'rendar_pc_settings[post_types][]=post' --data-urlencode 'rendar_pc_settings[post_types][]=portfolio' --data-urlencode 'rendar_pc_settings[post_types][]=memo' > /dev/null
check 'settings eligible scope stored' '["post","portfolio"]' "$(option)"
check 'settings scope effective' '["post","portfolio"]' "$(wp --path="$W" eval 'echo wp_json_encode(rendar_pc_get_post_types());')"
# Advisory admin publishes an incomplete synthetic post through normal REST.
published="$(curl -fsS -u "rpc_admin:$A" -H 'Content-Type: application/json' -d '{"title":"Synthetic incomplete","status":"publish"}' "$URL/index.php?rest_route=/wp/v2/posts")"
check 'incomplete admin publish succeeds' publish "$(jq -r '.status' <<< "$published")"
check 'gate not registered on normal boot' false "$(wp --path="$W" eval 'echo wp_json_encode(has_filter("wp_insert_post_data", "rendar_pc_gate_post_data") !== false);')"
# Metadata: scope registration and a real REST save/reload on both eligible types.
for kind in posts portfolio; do
  if [ "$kind" = posts ]; then id="$(jq -r '.id' <<< "$published")"; else id="$(wp --path="$W" post create --post_type=portfolio --post_title='Synthetic portfolio' --post_status=draft --porcelain)"; fi
  curl -fsS -u "rpc_admin:$A" -H 'Content-Type: application/json' -d '{"meta":{"_rendar_pc_decorative":["id:123","invalid"]}}' "$URL/index.php?rest_route=/wp/v2/$kind/$id" > /dev/null
  got="$(curl -fsS -u "rpc_admin:$A" "$URL/index.php?rest_route=/wp/v2/$kind/$id&context=edit" | jq -c '.meta._rendar_pc_decorative')"
  check "$kind decorative save/reload" '["id:123"]' "$got"
done
id="$(wp --path="$W" post create --post_type=memo --post_title='Synthetic memo' --post_status=draft --porcelain)"
check 'ineligible memo has no decorative meta' false "$(curl -fsS -u "rpc_admin:$A" "$URL/index.php?rest_route=/wp/v2/memo/$id&context=edit" | jq -r '.meta|has("_rendar_pc_decorative")')"
# Missing ACF at error severity must remain unavailable and advisory.
wp --path="$W" eval '$s=rendar_pc_get_settings();$s["severities"]["required-custom-fields"]="error";update_option(RENDAR_PC_OPTION,$s);' >/dev/null
report="$(curl -fsS -u "rpc_admin:$A" -H 'Content-Type: application/json' -d "$data" "$URL$route")"
check 'missing ACF check unavailable' unavailable "$(jq -r '.checks[] | select(.id=="required-custom-fields") | .status' <<< "$report")"
check 'missing ACF advisory' false "$(jq -r '.would_block' <<< "$report")"
INSTALLED="$(wp --path="$W" core version)"
check 'installed WP version matches request' "$VER" "$INSTALLED"
SHA="$(git -C "$ROOT" rev-parse HEAD)"
jq -cn --arg requested "$VER" --arg installed "$INSTALLED" --arg source_commit "$SHA" --arg php "$(php -r 'echo PHP_VERSION;')" --argjson checks "$N" '{requested:$requested,installed:$installed,source_commit:$source_commit,php:$php,checks:$checks,status:"pass"}' > "$R/evidence-$VER.json"
echo "PASS $N checks WP $INSTALLED PHP $(php -r 'echo PHP_VERSION;') source $SHA" >&2
