# Changelog

## 0.1.0

Initial release. Advisory evaluation only — no publish gate.

- Live article quality checks in the block editor: an always-on document panel
  and a pre-publish panel, both fed by the same server-side evaluator over a
  read-only REST route.
- Seven generic built-in checks, all at `warning` by default: featured image,
  featured-image minimum and ideal width (thresholds default to `0`, off),
  image alt text, tags, categories and ACF required custom fields.
- Settings screen: post types in scope (eligible REST/editor/custom-fields types
  only), per-check severity, width thresholds, classic-content exemption.
- Decorative-image acknowledgement stored in post meta, never in block markup.
- Activation watermark so pre-existing posts are not held to the checks.
- Public extension contract v1 (`docs/API.md`): the
  `rendar_prepublish_checks_definitions`, `..._block_images` and
  `..._settings_sections` hooks, the result schema (`pass`, `fail`,
  `not_applicable`, `unavailable`) and helpers. Malformed descriptors and
  results are logged and coerced, never a silent pass.
- Minimum environment (WordPress 6.6, PHP 7.4) enforced with a notice-only
  refusal below it.
- Enforcement subsystems (publish gate, scheduled re-check, issue lifecycle,
  failure notifications, admin issue queue) present but locked off.
- Automatic updates from public GitHub Releases; no site token or update service required.
