# Disposable real-WordPress acceptance (partial)

Run `python3 tests/integration/test-guard.py`, then

```sh
RPC_SQLITE_PLUGIN_DIR=/path/to/sqlite-database-integration \
  bash tests/integration/run.sh --wp 6.8.3   # also 6.6.2 / 7.0.4
```

Requires WP-CLI, PHP with SQLite extensions, curl, jq, openssl, lsof, and a
local copy of the [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration)
plugin named by `RPC_SQLITE_PLUGIN_DIR` (no default; the runner exits with a
message when it is unset). The runner uses BSD `stat -f`, so it currently
targets macOS. WP core downloads may use the WP-CLI cache. No Docker, database
server, remote URL, or configurable WP directory.

A checkout-owned `tests/.runtime/wp-<version>` SQLite site and loopback PHP
server are created then destroyed; a marker and symlink-refusing preflight
guard protect deletion and writer paths. A pre-existing owned fixture for the
requested version is replaced. The runner traps cleanup even on failure.
`tests/.runtime` is ignored and excluded by the ZIP allowlist. Only tracked
shipping-allowlisted plugin files are copied into the served fixture (never a
checkout symlink). The cookie jar is created per run under a mode-700
`/tmp/rpc-auth.*` directory, with a mode-600 file; both are removed on exit. The
guard tests use a separate disposable scaffold and leave any real runtime
untouched.

The runner emits sanitized check names/statuses to ignored per-version
`tests/.runtime/results-<version>.jsonl`, and the source SHA, installed
`wp core version`, PHP version and count to `evidence-<version>.json`; it does
not record auth, cookies, request bodies, nonces, or password values. The
loopback server must be owned by the launched PID and return a per-run fixture
token before auth begins; an HTTP 404 is asserted for the checkout cookie URL
and a docroot cookie URL after login. Real REST calls and an actual admin
login/nonce-backed `options.php` submission are used, with synthetic users and
posts. It does **not** load any dormant enforcement files, configure
`RENDAR_PC_ENFORCEMENT`, install ACF/GenerateBlocks, or trigger mail. Coverage
and gaps are listed in `docs/integration-acceptance.md`.
