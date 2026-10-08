# Rendar Prepublish Checks

A WordPress plugin that shows article quality checks live in the block editor
while an author writes — featured image, image alt text, tags, categories,
required custom fields — so problems are visible before an article goes out.

**Status: advisory only.** This release evaluates and reports; it does not hold
anything back from publishing. The enforcement subsystems (publish gate,
scheduled re-check, issue store, failure notifications, admin issue queue) are
in the codebase but locked off: `rendar_pc_enforcement_enabled()` returns
`false` unconditionally, even with `RENDAR_PC_ENFORCEMENT === true`. Do not set
the constant expecting protection. Unlocking it requires the issue lifecycle
plus publish/schedule and override acceptance against real WordPress, not
merely changing a constant.

## Requirements

- WordPress 6.6+ (the editor panel mounts through components that moved into
  `@wordpress/editor` in 6.6 — see `docs/ENVIRONMENT.md`)
- PHP 7.4+
- Optional: Advanced Custom Fields, for the required-custom-fields check

Below the minimum, the plugin shows an admin notice and loads nothing else.

## What it does

- **Editor panel.** An always-on document-sidebar panel and a pre-publish panel
  show each check's result as the author works. The panel always answers "what
  happens when this is published", regardless of the status being saved to.
- **Built-in checks** (all ship at `warning` severity): `featured-image`,
  `featured-image-min-width`, `featured-image-ideal-width`, `image-alt-text`,
  `post-tags`, `post-categories`, `required-custom-fields`.
- **Settings → Pre-Publish Checks.** Choose post types in scope, per-check
  severity (`error` / `warning` / `off`), featured-image width thresholds
  (`0` = off) and whether classic content is exempt from content checks.
- **Decorative images.** Authors can mark an image as decorative so the alt-text
  check accepts it. The marker is stored in post meta, never in block markup, so
  deactivating the plugin leaves no invalid blocks behind.
- **Watermark.** Posts created before the plugin was first activated are not
  held to the checks; the watermark is set once and never moved.

### Applicability and scope

The post-type setting offers public, REST-enabled post types with editor **and
custom-fields** support (WordPress does not expose registered post meta over
REST without `custom-fields`). The default is `post` only. Submission and reads
intersect the stored names with currently eligible registered types, so a stale
or forged option cannot widen scope; clearing every box gives an empty scope.
Meta registration runs on `init` at `PHP_INT_MAX`, after theme post types
registered at default or later priorities; post types first registered after
`init` are outside that guarantee.

Built-in checks declare versioned requirements (`docs/API.md`). A taxonomy not
registered on a post type yields `not_applicable`, not a false failure;
registration with no assignments still fails. Missing thumbnail/editor support
skips the corresponding checks. Missing ACF APIs return `unavailable` for
required fields; `unavailable` is never a pass.

## Extending

The stable extension surface is documented in `docs/API.md`: three hooks
(`rendar_prepublish_checks_definitions`, `rendar_prepublish_checks_block_images`,
`rendar_prepublish_checks_settings_sections`), the result helpers and the
read-only context. Core is deliberately neutral — site-specific editorial policy
belongs in a site extension (an mu-plugin, typically). A worked example lives in
`tests/fixtures/example-policy/`; it is never packaged.

## Automatic updates

The static `Update URI: https://updates.rendar.digital/rendar-prepublish-checks` prevents WordPress.org from offering an unrelated plugin; it is an identifier, not the API base. Each site must define both values in `wp-config.php` (never plugin options):

```php
define( 'RENDAR_UPDATES_URL', 'https://your-update-proxy.example' );
define( 'RENDAR_UPDATES_TOKEN', 'site-specific-token-from-secure-storage' );
```

Absent either constant, checks are quietly disabled. The bearer goes only to the configured HTTPS `/v1/` path and authenticated redirects are not followed. WordPress manages normal plugin auto-update opt-in and cron. Network-activate this plugin on multisite to register its update hooks across the network. `http_api_debug` listeners and HTTP loggers can see request arguments, including Authorization: redact that header before logging or displaying them.

Release by changing the header Version and `RENDAR_PC_VERSION` together to a stable X.Y.Z, committing and testing, tagging `vX.Y.Z`, pushing the tag, and publishing a non-draft GitHub Release. `.github/workflows/release.yml` runs tests, validates the tag/version/constant, reuses `bin/build-zip`, then attaches `rendar-prepublish-checks.zip` (root `rendar-prepublish-checks/`) and header-derived `info.json`. A `-dev` version is deliberately **not releasable**: a release tag cannot match it.

## Development

```sh
bash tests/run.sh       # lint, updater contract, unit, render and contract tests
bash tests/build-zip.sh # reproducible release zip of the shipping plugin only
```

`tests/test-updater.php` (part of `tests/run.sh`) needs a real WordPress core: set `WP_CORE_DIR` or run `bin/provision-wp-core` to fetch CI's pinned version (`docs/wp-core-pin.md`); it skips itself with a message if `WP_CORE_DIR` is unset outside CI, and CI hard-fails instead.

An opt-in disposable real-WordPress acceptance run lives in `tests/integration/`
(see its README and `docs/integration-acceptance.md`).

The release zip contains only `rendar-prepublish-checks.php`, `README.md`,
`CHANGELOG.md`, `inc/` and `assets/`.

## Honest scope

The committed unit tests exercise **stubbed WordPress contracts** (hook, route
and meta registration, boot gating, the evaluator against an in-memory
WordPress) and a simulated JS render. They are not a substitute for integration
against real WordPress's `parse_blocks()` and `WP_HTML_Tag_Processor`,
GenerateBlocks, ACF or a browser.

Known limitation: core suppresses the pre-publish slide-in sidebar for saves
targeting `pending`, so that checklist does not surface on those saves. The
always-on document panel still does.

## License

GPL-2.0-or-later. See `LICENSE`.
