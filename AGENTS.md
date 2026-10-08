# Contributor and agent guidance

- The shipping plugin is `rendar-prepublish-checks.php`, `inc/`, `assets/`,
  `README.md` and `CHANGELOG.md`. Everything else (`tests/`, `docs/`, `bin/`,
  CI) is development scaffolding and is never packaged by `bin/build-zip`.
- The plugin ships **advisory-only**: the gate, scheduler, issue store, notifier
  and admin issue queue stay dark behind `rendar_pc_enforcement_enabled()`,
  which returns `false` unconditionally. Do not unlock it without the
  acceptance work described in `README.md` and `docs/ISSUES.md`.
- Core stays neutral. Site-specific editorial policy belongs in a site extension
  built on the public contract in `docs/API.md`, not in core defaults.
- Storage keys (`_rendar_pc_*` post meta, `rendar_pc_*` options) are durable
  data. Renaming one is a migration, not a refactor.
- Block content must never depend on this plugin to render: store
  plugin-specific state in post meta, not in saved block markup.
- Run `bash tests/run.sh` before committing; CI also runs `bin/build-zip` and
  `tests/build-zip.sh`.
- Never put credentials in files, logs or test fixtures.
- Do not tag, publish a release or deploy without explicit authorization.
