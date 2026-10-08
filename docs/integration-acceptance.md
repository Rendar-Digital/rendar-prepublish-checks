# Disposable real-WordPress acceptance (partial)

`tests/integration/run.sh` runs the advisory plugin against actual WordPress on
a throwaway SQLite site served by a loopback PHP server, using real HTTP
requests. It has been exercised on WordPress **6.6.2, 6.8.3 and 7.0.4** with
PHP 8.2. Run it yourself to produce evidence for a given commit:

```sh
python3 tests/integration/test-guard.py
RPC_SQLITE_PLUGIN_DIR=/path/to/sqlite-database-integration \
  bash tests/integration/run.sh --wp 6.8.3
```

Each run writes sanitized per-version results (check names/statuses only) and a
summary (requested/installed WP version, PHP version, source commit, check
count) to the ignored `tests/.runtime/` directory. It never records auth,
cookies, nonces, request bodies or passwords. All users and content are
synthetic.

## Covered

Anonymous evaluate 401 and author/editor/admin evaluate 200; advisory
`would_block=false`, `can_override=false`, missing issue route, and no
normal-boot post-data gate; nonce rejection without option change and an actual
admin `options.php` form POST roundtrip retaining `post` plus an eligible
late-registered CPT while dropping an unsupported CPT; normal REST publish of an
incomplete post; decorative meta REST save/reload on a post and an eligible CPT
with a malformed marker removed, and its absence on an ineligible CPT; missing
ACF at error severity returns `unavailable` and remains advisory.

## Not yet accepted

options.php non-admin capability denial (nonce denial is tested); taxonomy check
semantics across CPTs without registered taxonomies; real browser mounting of
the document panel / pre-publish sidebar (especially core's pending-save
suppression); ACF-installed field resolution, GenerateBlocks content, editor
controls, editor save/reload beyond REST meta; dormant enforcement integration
(pre-row override denial, autosave and revision integrity, late-CPT gate hooks,
admin override, cron unavailable issue), mail and the first-published
lifecycle. No claim of MySQL behaviour, concurrency, outbound mail delivery or
browser UI is made. Enforcement remains locked off even with the opt-in
constant; do not unlock it on the strength of this run.

## Safety

The guard and runner live under `tests/integration/` and are never packaged.
Before creating the fixture, the runner refuses a checkout with tracked changes
in shipping files or the integration harness, or any untracked shipping file,
so the recorded source commit is exactly what was served. The guard refuses
symlinked or unowned fixture paths and any non-loopback URL.
