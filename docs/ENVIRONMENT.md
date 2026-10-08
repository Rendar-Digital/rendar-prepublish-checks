# Minimum environment — the API audit behind it

The plugin declares `RENDAR_PC_MIN_WP = 6.6` and `RENDAR_PC_MIN_PHP = 7.4`
(`rendar-prepublish-checks.php`), mirrored in the plugin header's
`Requires at least` / `Requires PHP`. Below the minimum, `rendar_pc_boot()`
registers **only** an environment notice and loads no subsystem.
`rendar_pc_unsupported_environment()`
is the single decision; `tests/test-environment.php` pins it.

The minimum is **derived from the APIs the plugin actually calls**, not chosen.
This file is that audit. The rule: the declared minimum is the newest WordPress
version any required API needs.

## Why 6.6 and not 6.2

An earlier draft declared 6.2 on the strength of `WP_HTML_Tag_Processor` alone.
That was wrong: the editor panel mounts through two components that did not live
in `@wordpress/editor` until **WordPress 6.6**. On 6.5 and earlier,
`wp.editor.PluginDocumentSettingPanel` and `wp.editor.PluginPrePublishPanel` are
`undefined`, `registerPlugin()` renders nothing, and the live checks have no UI
at all — a silent, total failure of the only surface the advisory plugin ships. 6.6 is the
binding constraint; `WP_HTML_Tag_Processor` (6.2) is comfortably subsumed.

## JavaScript API audit (`assets/js/editor.js`)

| API used | Package / global | First usable as called | Note |
| --- | --- | --- | --- |
| `PluginDocumentSettingPanel` | `wp.editor` | **6.6** | Moved from `@wordpress/edit-post` to `@wordpress/editor` in 6.6. **Binding.** |
| `PluginPrePublishPanel` | `wp.editor` | **6.6** | Same move in 6.6. **Binding.** |
| `registerPlugin` | `wp.plugins` | 5.0 | |
| `createElement`, `Fragment` | `wp.element` | 5.0 | |
| `useState`, `useEffect`, `useRef` | `wp.element` | 5.0 (React 16.8) | |
| `useSelect` | `wp.data` | 5.3 | |
| `useEntityProp` | `wp.coreData` | 5.3 | |
| `apiFetch` | `wp.apiFetch` | 5.0 | |
| `__`, `_n`, `sprintf` | `wp.i18n` | 5.0 | |
| `Button`, `Spinner`, `TextareaControl`, `Notice` | `wp.components` | ≤ 5.9 | |
| `TextareaControl` `__nextHasNoMarginBottom` | `wp.components` | 6.1 | Opt-in prop; absence is harmless, not fatal. |
| `createErrorNotice`, `removeNotice` | `wp.data` (`core/notices`) | 5.0 | |
| `receiveEntityRecords` | `wp.data` (`core`) | 5.0 | |

The two 6.6 components are the only APIs newer than 6.2. Everything else is 6.1
or older.

## PHP API audit (`inc/*.php`, advisory core only)

Enforcement-gated files (`inc/gate.php`, `scheduled.php`, `issues.php`,
`notify.php`, `admin-issues.php`) are not loaded while enforcement is locked off; their APIs are not
part of the advisory-core minimum and are not audited here.

| API used | Where | First shipped | Note |
| --- | --- | --- | --- |
| `WP_HTML_Tag_Processor` | `inc/images.php` | **6.2** | Image discovery engine. Newest PHP-side dependency. |
| `parse_blocks`, `has_blocks` | `inc/images.php`, context | 5.0 | |
| `register_post_meta` | `inc/meta.php` | 4.9.8 | |
| `register_rest_route`, `rest_ensure_response` | `inc/rest.php` | 4.4 | |
| `register_setting`, `add_options_page` | `inc/admin-settings.php` | ≤ 4.7 | |
| `get_ancestors`, `wp_get_post_terms`, `get_term` | context, fixture | ≤ 3.1 | |
| `wp_get_attachment_metadata`, `get_edit_post_link` | `inc/checks.php` | ≤ 2.5 | |
| `get_post_thumbnail_id`, `get_post_meta` | context | ≤ 2.9 | |
| arrow functions, typed properties | throughout | PHP 7.4 | Language floor. |

No PHP API in the advisory core is newer than WordPress 6.2, and no language
feature is newer than PHP 7.4. The WordPress minimum is therefore governed
entirely by the JavaScript panel (6.6); the PHP minimum is the language floor
(7.4).

## Keeping this honest

When a new WordPress or PHP API is introduced into the advisory core or the
editor script, add a row here and raise `RENDAR_PC_MIN_WP` / `RENDAR_PC_MIN_PHP`
in the same change if the new API's floor is higher than the current minimum.
`tests/test-environment.php` asserts the declared constants and the notice-only
refusal below them; it is not a substitute for this audit.
