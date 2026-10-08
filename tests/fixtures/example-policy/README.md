# Example site policy — non-shipped fixture site extension

`example-policy.php` shows how a site layers its own editorial policy on top of
the plugin's **neutral core**, using only the three public v1 extension filters
(`docs/API.md`):

- `rendar_prepublish_checks_definitions` — a severity policy (which checks
  block, which only advise) plus three site-specific editorial checks:
  `image-credit` (caption-as-photo-credit), `default-category-only` and
  `category-ancestors` (default-category ancestry).
- `rendar_prepublish_checks_block_images` — an adapter for a hypothetical
  `example/gallery` block whose images live in a block attribute rather than
  in the saved markup.
- `rendar_prepublish_checks_settings_sections` — a "Save Log" diagnostics
  section with its own option, rendered in the plugin's settings screen.

## It is never shipped

It lives under `tests/`, so `bin/build-zip` (which packages only `<slug>.php`,
`README.md`, `CHANGELOG.md`, `inc/*` and `assets/*`, and rejects any
`tests`/`docs` path) can never put it in a release. In a real deployment the
equivalent would be an mu-plugin.

## Policy values live in settings, not here

The featured-image width thresholds and the publishing-failure recipient are
**stored settings** an administrator configures, not extension code. There is
deliberately no value-injection filter in the v1 contract.

## What proves it

- `tests/test-fixture-public-api.php` tokenises the fixture and fails if it
  references any core `rendar_pc_*` function or constant outside the documented
  public contract.
- `tests/test-save-log-setting.php` exercises its Settings API registration and
  form submission path.
