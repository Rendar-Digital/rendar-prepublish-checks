# Public extension contract — v1

This is the **stable** surface third-party code may build on. It is versioned:
breaking a v1 shape means a v2, not a silent change. Everything not listed here
(internal `rendar_pc_*` functions, storage keys, REST response internals) is
private and may change without notice.

The v1 contract has **three filters/actions**, the result helpers and the
context/image helpers documented below. The issue and notify hooks are
**provisional enforcement internals** and are not part of public v1.

Core is neutral: it ships the generic checks only. For a worked example of a
site policy built on nothing but this contract, see
`tests/fixtures/example-policy/`.

---

## Result schema

Every check callback returns an array with exactly these keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `status` | string | One of the four statuses below. |
| `message` | string | Human-readable, shown in the panel. |
| `offenders` | array[] | Optional. Each has a non-empty string `key` (stable id); `label` and `edit_url`, when present, are strings. Missing `label` defaults to `key`. `id` and `src` are optional metadata. |

Build results with the core helpers rather than by hand:

| Helper | `status` | Use when |
| --- | --- | --- |
| `rendar_pc_pass( $message )` | `pass` | The check is satisfied. |
| `rendar_pc_fail( $message, $offenders = [] )` | `fail` | The check is not satisfied. |
| `rendar_pc_not_applicable( $message )` | `not_applicable` | The check **does not apply** to this post. |
| `rendar_pc_unavailable( $message )` | `unavailable` | The check **applies but cannot be answered** (a capability it needs is absent). |

An explicit `null` for `label` or `edit_url` is invalid, not an omitted
optional field. Valid optional metadata (`id`, `src`) is preserved.

`not_applicable` and `unavailable` are deliberately distinct, and **neither is a
pass**. "Not applicable" says the check does not apply to this post (e.g. no ACF
group targets it). "Unavailable" says the check applies but the environment
cannot answer it (e.g. ACF or `WP_HTML_Tag_Processor` is missing) — the author
is told the truth instead of being shown a green tick that means nothing. A
callback that returns anything that is not one of these four shapes — including
an offender with a missing/non-string `key`, a non-string `label`, or a
non-string `edit_url` — is treated as `unavailable` ("this check did not
return a valid result"), never a silent pass.

### Severity

Severity is configured, not fixed. A descriptor declares a **default** severity;
a site's stored settings win over it. Values: `error` (blocking when enforcement
is on), `warning` (advisory), `off` (the check does not run at all). An
invalid **stored** severity falls back to that check's validated descriptor
default (which may be `error` and therefore blocking). An invalid extension
descriptor severity defaults to `warning`; it is logged as an invalid field.

---

## Public PHP helper and context surface

Extensions may use `rendar_pc_pass()`, `rendar_pc_fail()`,
`rendar_pc_not_applicable()` and `rendar_pc_unavailable()` as described above.
The following generic helpers are also v1:

| Helper | Contract |
| --- | --- |
| `rendar_pc_image_offender( $image )` | Returns an offender for a normalized image descriptor (`key`, `attachment_id`, `src`); includes `key`, `id`, `label`, `src`, `edit_url`. |
| `rendar_pc_caption_is_blank( $value )` | True for an empty/whitespace-only caption, including Unicode whitespace. |
| `rendar_pc_classic_skip_message()` | The shared human-readable reason content checks stand down for classic content. |

The callback receives `Rendar_PC_Context`. Its v1 readable fields are
`post_id`, `post_type`, `current_status`, `target_status`, `was_published`,
`predates_checks`, `content`, `has_real_blocks`, `featured_id`, `category_ids`,
`tag_ids`, `images`, `acf_available`, `matched_field_groups`,
`missing_required_fields`, `decorative_keys` and `override`. The method
`skips_content_checks()` returns whether classic content is exempt from
content-image checks. `images` entries carry `key`, `attachment_id`, `src`,
`alt`, `caption`, `block_name`. This is a read-only consumer contract: do not
mutate the context in a callback. All other core functions and methods remain
private; notably there is no core dynamic-token credit exemption — that is
site policy, shown in the example fixture.

---

## 1. `rendar_prepublish_checks_definitions` (filter)

Add, remove, or reconfigure checks. Receives the checks array, **keyed by check
id**, and must return the same shape.

```php
add_filter( 'rendar_prepublish_checks_definitions', function ( $checks ) {
	$checks['my-check'] = array(
		'label'       => __( 'My check', 'my-text-domain' ),
		'description' => __( 'What this check wants.', 'my-text-domain' ),
		'severity'    => 'warning',            // error | warning | off
		'applies_to'  => array( '*' ),          // post types, or ['*'] for all configured types
		'requirements' => array( 'version' => 1, 'taxonomies' => array( 'category' ) ),
		'callback'    => 'my_check_callback',   // fn( Rendar_PC_Context ): array (a result above)
	);

	// Reconfigure a built-in: e.g. make the alt-text check blocking.
	if ( isset( $checks['image-alt-text'] ) ) {
		$checks['image-alt-text']['severity'] = 'error';
	}

	return $checks;
} );
```

### Descriptor fields

| Field | Required | Notes |
| --- | --- | --- |
| `callback` | **yes** | Must be callable and accept one `Rendar_PC_Context`. A non-callable callback rejects the whole descriptor. |
| `label` | no | Defaults to the id. A non-scalar is rejected (not cast) and the id is used. |
| `description` | no | Defaults to empty. A non-scalar is rejected (not cast). |
| `severity` | no | Defaults to `warning`. An invalid value is rejected and `warning` is used. |
| `applies_to` | no | Array of post type names, or `['*']`. Omission defaults to `['*']`; a malformed explicit value rejects the definition. |
| `requirements` | no | Versioned applicability descriptor below. Omission means `{version: 1}` with no declared requirements. |

### Applicability descriptor (version 1)

`requirements` is an array with **integer** `version => 1` and optional lists:
`taxonomies` (`category`, `post_tag`), `supports` (`editor`, `thumbnail`),
`dependencies` (`acf`, `html_tag_processor`). All lists must be sequential,
unique, and contain only these literal strings; unknown keys/versions or
malformed values reject the entire check, with a reason in the invalid-definition
log. Declare all semantic prerequisites: a custom callback with no declared
requirements is responsible for its own unavailable/not-applicable result.

The evaluator tests declared prerequisites **before invoking** the callback:
a taxonomy not registered on the post type or unsupported feature returns
`not_applicable`; a missing ACF API or HTML tag processor needed for nonempty
block content returns `unavailable`. A registered taxonomy with zero assigned
terms still runs and may fail. The WP HTML processor is part of the minimum
WordPress 6.6 environment; an absent processor is not confused with missing ACF.
Built-in category/tag checks require their matching taxonomy; featured checks
require thumbnail support; image alt requires editor support and the processor;
required fields require ACF's group, field, and value APIs. The same report
feeds REST, editor panel and the currently disabled gate. Malformed
definitions are skipped. Gate policy for when enforcement is unlocked (tested
against stubs only; enforcement remains unconditionally off): error-level `unavailable`, including malformed
callback results, contributes to `blocking_ids` but not `failing_errors`.
A missing/rejected descriptor contributes a named `unavailable` sentinel only
when its ID is explicitly stored at `error` severity. Warning unavailability
is advisory; off checks are not run. Applicability skips remain
`not_applicable`. An admin override must name every blocking ID. Cron holds
error-level incomplete evaluations rather than publishing them; advisory-only
incomplete evaluations preserve earlier issues instead of resolving them.
`failing_errors` remains failures only; `unavailable_errors` names missing
answers; `blocking_ids` is their union for overrides, REST gate messages and
scheduled holds. The advisory editor still renders unavailable distinctly,
while `would_block` remains false until enforcement is authorized.

**Remaining real-WP acceptance before any unlock:** run publish/future/draft/
pending REST writes against the real posts controller (including admin and
nonadmin override meta authorization); editor panel refresh and publish-lock
roundtrips; real ACF absence and invalid filtered descriptors; scheduled cron
with an existing issue and an expired attempt binding; direct non-REST status
writers, and multi-listener cron races. Stub tests cannot prove WP hook timing,
REST schema ordering, or that a competing publisher cannot expose a row between
core's direct write and the fallback hold. No release or deployment follows
from this dormant decision.

### Validation is safe by construction

The id is **always the array key** (sanitised) — a descriptor cannot set its own
id through a reserved `id` field, and cannot overwrite another check. Malformed
descriptors are **logged and skipped, never fatal and never a PHP warning**:

- non-array definition value → skipped;
- missing or non-callable `callback` → skipped;
- empty / non-string id → skipped;
- two ids that sanitise to the same value → the **first** is kept, the rest
  skipped (never a silent overwrite);
- malformed explicit `applies_to` or `requirements` → descriptor skipped;
- non-scalar `label`/`description`, or an invalid `severity` → the field is
  coerced to its safe default and the coercion is logged.

The rejection log for the most recent pass is available to tooling via
`rendar_pc_invalid_definitions()` (a list of `['id' => ..., 'reason' => ...]`).

---

## 2. `rendar_prepublish_checks_block_images` (filter)

A block that knows something its saved markup cannot express describes its images
here — the one place both the live panel and the publish gate read from. Receives
the descriptors discovered in the block's own markup, plus the parsed block;
returns descriptors.

```php
add_filter( 'rendar_prepublish_checks_block_images', function ( $images, $block ) {
	if ( 'my/gallery' !== ( $block['blockName'] ?? '' ) ) {
		return $images;
	}

	return array(
		array(
			'attachment_id' => 123,                 // 0 if none
			'src'           => 'https://…/x.jpg',
			'alt'           => 'Alt text',
			'caption'       => 'A credit the markup cannot carry',
			'block_name'    => 'my/gallery',
		),
	);
}, 10, 2 );
```

### Descriptor fields

| Field | Type | Notes |
| --- | --- | --- |
| `attachment_id` | int | `0` when the image is not in the media library. |
| `src` | string | The image URL. |
| `alt` | string | Alt text; drives the alt-text check. |
| `caption` | string | A credit the content supplies; consulted after the attachment's own caption. |
| `block_name` | string | The owning block name. |
| `markup_decorative` | bool | Whether the saved markup explicitly marks this image decorative. Only literal `true` is accepted; malformed values, including strings, arrays and objects, become `false`. |

Missing fields are filled in by core, so returning just the facts the markup
cannot express is enough. Returning an **empty array** removes the block's images
from the evaluation entirely — a deliberate decision, not a way to silence a
check by accident. A descriptor carrying an array where a scalar belongs is
discarded at the normalisation choke point, never cast to the literal `"Array"`.

---

## 3. `rendar_prepublish_checks_settings_sections` (action)

Render an extra section on the Pre-Publish Checks settings screen. The owning
extension owns its option, its default and its sanitising; the screen only offers
the slot. If the extension is deactivated the section disappears and the option
keeps its last value.

```php
add_action( 'rendar_prepublish_checks_settings_sections', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// echo your <h2> + form-table section here; register your option with
	// register_setting( 'rendar_pc', 'my_option', [...] ) on admin_init.
} );
```

---

## Provisional enforcement internals (NOT public v1)

`rendar_prepublish_checks_issue_finalized`, `rendar_prepublish_checks_schedule_failed`,
`rendar_prepublish_checks_backstop_demoted`, `rendar_prepublish_checks_failure_recipients`,
and the `/issue` route are implemented but gated behind the unconditional-off
enforcement switch. Their current internal shapes are described in `docs/ISSUES.md`
for testing, not as public compatibility promises. A future enforcement release
must explicitly version and publish these contracts before third-party use.
