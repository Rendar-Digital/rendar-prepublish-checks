<?php
/**
 * Required-custom-field resolution.
 *
 * Which ACF field groups apply to a post, and which of their required fields
 * are currently empty. Resolved once in Rendar_PC_Context::build() so the
 * check callback only ever reads $context->missing_required_fields — the same
 * discipline images.php follows for content images.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * ACF field groups whose location rules match this post.
 *
 * Delegates entirely to ACF's own location-matching machinery
 * (acf_get_field_groups() -> filter_posts() -> acf_get_field_group_visibility())
 * rather than re-implementing rule evaluation, so a group targeting any
 * location type ACF supports resolves correctly here — not only post_type and
 * post_category.
 *
 * post_type and post_category/post_tag are supplied explicitly from the
 * context rather than left to ACF's own post_id fallback, for the same reason
 * Rendar_PC_Context resolves categories itself: the editor's pending
 * selection is the only place "no category yet" is observable, and a category
 * added in the same edit that adds a required field must be seen immediately.
 * Any other location rule type a future group might use (author, template,
 * post format…) still falls back to reading the saved post via post_id, which
 * is a reasonable default.
 *
 * @param string $post_type    Post type being evaluated.
 * @param int[]  $category_ids Category term IDs being evaluated.
 * @param int[]  $tag_ids      Tag term IDs being evaluated.
 * @param int    $post_id      Post ID, 0 for an unsaved post.
 * @return array[] ACF field group arrays.
 */
function rendar_pc_matching_field_groups( $post_type, array $category_ids, array $tag_ids, $post_id ) {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return array();
	}

	return (array) acf_get_field_groups(
		array(
			'post_id'    => $post_id,
			'post_type'  => $post_type,
			'post_terms' => array(
				'category' => $category_ids,
				'post_tag' => $tag_ids,
			),
		)
	);
}

/**
 * Required fields, across every matching group, that are currently empty.
 *
 * "Empty" mirrors ACF's own required-field rule in acf_validate_value():
 * empty( $value ) && ! is_numeric( $value ) — 0 counts as a real, deliberately
 * entered value. Reads the saved post's value by default; a value keyed by
 * field name in $meta takes precedence when supplied, which is the same
 * override-else-saved-post shape Rendar_PC_Context already uses for
 * decorative acknowledgments. Nothing sends a field-name-keyed override today
 * — the live panel only ever asks about featured image, terms and content —
 * but the mechanism is here for a future live-value panel to use without a
 * second resolution path.
 *
 * @param array[] $field_groups Matching ACF field groups.
 * @param int     $post_id      Post ID, 0 for an unsaved post.
 * @param array   $meta         Meta/value overrides supplied with the request.
 * @return array[] Offender entries, one per empty required field.
 */
function rendar_pc_missing_required_fields( array $field_groups, $post_id, array $meta ) {
	if ( ! function_exists( 'acf_get_fields' ) ) {
		return array();
	}

	$offenders = array();

	foreach ( $field_groups as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}

			$value = rendar_pc_read_field_value( $field, $post_id, $meta );

			if ( ! empty( $value ) || is_numeric( $value ) ) {
				continue;
			}

			$offenders[] = rendar_pc_field_offender( $field, $group, $post_id );
		}
	}

	return $offenders;
}

/**
 * One field's current value: an override first, else the saved post.
 *
 * @param array $field   ACF field array.
 * @param int   $post_id Post ID, 0 for an unsaved post.
 * @param array $meta    Meta/value overrides supplied with the request.
 * @return mixed
 */
function rendar_pc_read_field_value( array $field, $post_id, array $meta ) {
	if ( array_key_exists( $field['name'], $meta ) ) {
		return $meta[ $field['name'] ];
	}

	if ( ! $post_id || ! function_exists( 'get_field' ) ) {
		return null;
	}

	// Unformatted, to compare the same raw value acf_validate_value() would
	// have seen at submit time rather than a display-formatted derivative.
	return get_field( $field['key'], $post_id, false );
}

/**
 * Build an offender entry for a missing required field.
 *
 * No edit_url. Unlike an image offender — which may point at a different
 * object's own edit screen — this field lives in a classic metabox on the
 * very screen the panel is already showing; get_edit_post_link( $post_id )
 * would be a same-page link with nothing to navigate to. It would also,
 * because it is truthy, make the panel's shared offender row render its
 * hardcoded "Edit image" link text, which is wrong for a text field.
 *
 * @param array $field   ACF field array.
 * @param array $group   ACF field group array the field belongs to.
 * @param int   $post_id Post ID, 0 for an unsaved post.
 * @return array
 */
function rendar_pc_field_offender( array $field, array $group, $post_id ) {
	return array(
		'key'      => 'acf:' . $field['key'],
		'id'       => (int) $post_id,
		'label'    => sprintf(
			/* translators: 1: field label, 2: field group title. */
			__( '%1$s (%2$s)', 'rendar-prepublish-checks' ),
			$field['label'],
			$group['title']
		),
		'edit_url' => '',
	);
}
