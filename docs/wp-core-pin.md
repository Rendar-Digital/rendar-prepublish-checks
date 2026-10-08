# Pinned WordPress core for `tests/test-updater.php`

`tests/test-updater.php` runs the real `Rendar_PC_Updater` class against an
actual WordPress core (`class-wp-http.php`, its HTTP header parser, and the
real `WpOrg\Requests\Iri` dot-segment behavior) rather than a stub, because
the stale-leak and base-URL-confinement assertions it makes are only
meaningful against WordPress's own parsing. It needs a `WP_CORE_DIR`
environment variable pointing at a WordPress installation root (a directory
containing `wp-load.php` and `wp-includes/`).

## The pin

| | |
|---|---|
| Version | **6.8.3** |
| Download | `https://downloads.wordpress.org/release/wordpress-6.8.3.zip` |
| sha1 (published by WordPress.org at `<download-url>.sha1`) | `b676fb6744910d58180fd58597cc09bb2c5d4a2a` |
| sha256 (computed locally against the downloaded archive) | `a163fe8d0d3d89ce00139ca0e0618e109bb7441fae2f733cff6c72fc4d170fb9` |

Captured 2026-10-06 by downloading the archive over HTTPS from
`downloads.wordpress.org`, computing its sha256 locally, and cross-checking
both against the sha1 WordPress.org itself publishes at
`wordpress-6.8.3.zip.sha1` (which matched). `bin/provision-wp-core` re-checks
the live sidecar against this pin on every fresh download — not just the
recorded value — so a rotated or corrupted CDN response is caught rather than
silently accepted.

### Why 6.8.3, not 7.0.4

The disposable real-WP acceptance run (`docs/integration-acceptance.md`) has
been exercised on 6.6.2, 6.8.3 and 7.0.4. Either 6.8.3 or 7.0.4 would satisfy
"pin a version the acceptance run covers." 6.8.3 was chosen to match the
sibling `rendar-editorial-controls` plugin's pin, so both plugins' CI test
against the same core. There is no other functional reason to prefer it over
7.0.4; re-pinning to a later version that has passed the acceptance run is a
reasonable future change.

## How CI uses it

`.github/workflows/check.yml` and `.github/workflows/release.yml` both run
`bin/provision-wp-core` before any test step and export the printed path as
`WP_CORE_DIR` for the rest of the job. `tests/test-updater.php` hard-fails
(not skips) when `WP_CORE_DIR` is unset and the `CI` environment variable is
present — which GitHub Actions sets on every run — so a provisioning failure
cannot silently downgrade into a pass. An `actions/cache` step keyed on the
hash of `bin/provision-wp-core` caches the downloaded zip across runs; the
cache is optional (the script re-downloads and re-verifies if the cache is
cold or the pin changes) and is never trusted blindly — the pinned
sha1/sha256 are re-verified against whatever is in the cache before it is
used.

## Running it locally

```sh
# One-off, explicit path:
WP_CORE_DIR=/path/to/a/wordpress/install php tests/test-updater.php

# Or reproduce what CI does — downloads, verifies, and prints the core root:
core=$(bash bin/provision-wp-core)
WP_CORE_DIR="$core" php tests/test-updater.php unconfigured
WP_CORE_DIR="$core" php tests/test-updater.php
```

`tests/run.sh` invokes `tests/test-updater.php` as part of its normal run; set
`WP_CORE_DIR` before calling it for that step to actually exercise rather
than skip. If `WP_CORE_DIR` is unset and `CI` is not set,
`tests/test-updater.php` skips itself with a message rather than failing on a
path that only exists on one developer's machine.

## Re-pinning

Update `WP_CORE_VERSION`, `WP_CORE_SHA1` and `WP_CORE_SHA256` in
`bin/provision-wp-core` together, after:

1. Confirming the new version has real-WordPress acceptance evidence (not
   just that `tests/test-updater.php` happens to run against it).
2. Downloading the new version's zip and sha1 sidecar from
   `downloads.wordpress.org` yourself and computing the sha256 locally —
   never copy a hash from somewhere other than the artifact you are about to
   pin.
3. Updating the table above and the "Why" note in this document.
