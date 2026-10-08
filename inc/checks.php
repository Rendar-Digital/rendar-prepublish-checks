<?php
/**
 * The built-in checks.
 *
 * Each one is a pure function of the context. None of them reads request state,
 * global state, or the database for anything the context should have carried —
 * that discipline is what lets the same callback serve the live panel and the
 * publish gate.
 *
 * This file ships only the GENERIC checks — the ones that apply to any
 * editorial WordPress site and name nothing site-specific. The featured-image
 * width checks are generic and threshold-driven (a width of 0 turns one off);
 * the threshold VALUES are policy, stored settings a site configures.
 * required-custom-fields names no particular field: it resolves whatever ACF
 * groups ACF's own rules say apply, and reports unavailable (never a silent
 * pass) when ACF is absent.
 *
 * Genuinely site-specific editorial policy (for example caption-as-photo-credit
 * or category-ancestry rules) belongs in a site extension registered through the
 * public `rendar_prepublish_checks_definitions` filter. See docs/API.md and the
 * example fixture in tests/fixtures/example-policy/.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Definitions for the generic checks this plugin ships.
 *
 * Neutral defaults: every built-in check ships at WARNING. A site escalates the
 * ones it treats as blocking through stored severity settings or the definitions
 * filter — which is exactly how the example fixture applies an error-severity
 * policy. Core pins no severity so a descriptor-level change can take effect.
 *
 * @return array[]
 */
function rendar_pc_builtin_checks() {
	return array(
		'featured-image'             => array(
			'label'       => __( 'Featured image', 'rendar-prepublish-checks' ),
			'description' => __( 'The article has a featured image.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_featured_image',
			'requirements' => array( 'version' => 1, 'supports' => array( 'thumbnail' ) ),
		),
		'featured-image-min-width'   => array(
			'label'       => __( 'Featured image size', 'rendar-prepublish-checks' ),
			'description' => __( 'The featured image meets the minimum width.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_featured_min_width',
			'requirements' => array( 'version' => 1, 'supports' => array( 'thumbnail' ) ),
		),
		'featured-image-ideal-width' => array(
			'label'       => __( 'Featured image quality', 'rendar-prepublish-checks' ),
			'description' => __( 'The featured image meets the preferred width.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_featured_ideal_width',
			'requirements' => array( 'version' => 1, 'supports' => array( 'thumbnail' ) ),
		),
		'image-alt-text'             => array(
			'label'       => __( 'Image alt text', 'rendar-prepublish-checks' ),
			'description' => __( 'Every image in the article describes itself to screen readers.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_image_alt',
			'requirements' => array( 'version' => 1, 'supports' => array( 'editor' ), 'dependencies' => array( 'html_tag_processor' ) ),
		),
		'post-tags'                  => array(
			'label'       => __( 'Tags', 'rendar-prepublish-checks' ),
			'description' => __( 'The article has at least one tag.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_tags',
			'requirements' => array( 'version' => 1, 'taxonomies' => array( 'post_tag' ) ),
		),
		'post-categories'            => array(
			'label'       => __( 'Categories', 'rendar-prepublish-checks' ),
			'description' => __( 'The article is filed under at least one category.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_categories',
			'requirements' => array( 'version' => 1, 'taxonomies' => array( 'category' ) ),
		),
		'required-custom-fields'     => array(
			'label'       => __( 'Required custom fields', 'rendar-prepublish-checks' ),
			'description' => __( 'Every required custom field for this article\'s post type and category is filled in.', 'rendar-prepublish-checks' ),
			'severity'    => RENDAR_PC_SEVERITY_WARNING,
			'callback'    => 'rendar_pc_check_required_fields',
			'requirements' => array( 'version' => 1, 'dependencies' => array( 'acf' ) ),
		),
	);
}

/**
 * A featured image is set.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_featured_image( Rendar_PC_Context $context ) {
	if ( $context->featured_id > 0 ) {
		return rendar_pc_pass( __( 'A featured image is set.', 'rendar-prepublish-checks' ) );
	}

	return rendar_pc_fail( __( 'This article has no featured image.', 'rendar-prepublish-checks' ) );
}

/**
 * The featured image is at least the minimum width.
 *
 * Generic and threshold-driven: a width of 0 turns it off. Core defaults the
 * threshold to 0 (off); a site configures the minimum it wants. The 1200 here
 * is only the fallback when the key is wholly absent.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_featured_min_width( Rendar_PC_Context $context ) {
	return rendar_pc_featured_width_result(
		$context,
		(int) rendar_pc_get_setting( 'min_featured_width', 1200 ),
		/* translators: 1: image width in pixels, 2: required width in pixels. */
		__( 'The featured image is %1$dpx wide. The minimum is %2$dpx.', 'rendar-prepublish-checks' ),
		/* translators: %d: required width in pixels. */
		__( 'The featured image is at least %dpx wide.', 'rendar-prepublish-checks' )
	);
}

/**
 * The featured image is at least the preferred width.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_featured_ideal_width( Rendar_PC_Context $context ) {
	return rendar_pc_featured_width_result(
		$context,
		(int) rendar_pc_get_setting( 'ideal_featured_width', 1600 ),
		/* translators: 1: image width in pixels, 2: preferred width in pixels. */
		__( 'The featured image is %1$dpx wide. %2$dpx or wider reproduces better across the site.', 'rendar-prepublish-checks' ),
		/* translators: %d: preferred width in pixels. */
		__( 'The featured image is at least %dpx wide.', 'rendar-prepublish-checks' )
	);
}

/**
 * Shared body for the two featured-image width checks.
 *
 * They are separate checks rather than one two-tier check because severity is
 * configured per check — a single dropdown cannot govern two consequences.
 *
 * @param Rendar_PC_Context $context      Context.
 * @param int               $threshold    Required width in pixels; 0 disables.
 * @param string            $fail_format  sprintf format for the failure message.
 * @param string            $pass_format  sprintf format for the pass message.
 * @return array
 */
function rendar_pc_featured_width_result( Rendar_PC_Context $context, $threshold, $fail_format, $pass_format ) {
	if ( $threshold <= 0 ) {
		return rendar_pc_not_applicable( __( 'No width requirement is configured.', 'rendar-prepublish-checks' ) );
	}

	if ( ! $context->featured_id ) {
		// The missing-image case belongs to its own check. Reporting it twice
		// would make one mistake look like two.
		return rendar_pc_not_applicable( __( 'No featured image is set yet.', 'rendar-prepublish-checks' ) );
	}

	$width = rendar_pc_attachment_width( $context->featured_id );

	if ( null === $width ) {
		return rendar_pc_not_applicable(
			__( 'The featured image has no stored dimensions, so its width could not be checked.', 'rendar-prepublish-checks' )
		);
	}

	if ( $width >= $threshold ) {
		return rendar_pc_pass( sprintf( $pass_format, $threshold ) );
	}

	return rendar_pc_fail(
		sprintf( $fail_format, $width, $threshold ),
		array(
			array(
				'key'      => 'id:' . $context->featured_id,
				'id'       => $context->featured_id,
				'label'    => rendar_pc_attachment_label( $context->featured_id ),
				'edit_url' => rendar_pc_attachment_edit_url( $context->featured_id ),
			),
		)
	);
}

/**
 * Every content image has alt text.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_image_alt( Rendar_PC_Context $context ) {
	if ( $context->skips_content_checks() ) {
		return rendar_pc_not_applicable( rendar_pc_classic_skip_message() );
	}

	if ( ! $context->images ) {
		return rendar_pc_pass( __( 'This article has no images in its content.', 'rendar-prepublish-checks' ) );
	}

	$offenders = array();

	foreach ( $context->images as $image ) {
		if ( '' !== $image['alt'] ) {
			continue;
		}

		// Two ways an image is exempt, and they are not the same thing. Markup
		// that already says role="presentation" or aria-hidden is authoritative
		// — the author has declared it. Everything else needs the author to say
		// so explicitly, because core's own decorative signal is an empty alt,
		// which is indistinguishable from having forgotten.
		if ( $image['markup_decorative'] ) {
			continue;
		}

		if ( in_array( $image['key'], $context->decorative_keys, true ) ) {
			continue;
		}

		$offenders[] = rendar_pc_image_offender( $image );
	}

	if ( ! $offenders ) {
		return rendar_pc_pass( __( 'Every image has alt text.', 'rendar-prepublish-checks' ) );
	}

	return rendar_pc_fail(
		sprintf(
			/* translators: %d: number of images. */
			_n(
				'%d image has no alt text.',
				'%d images have no alt text.',
				count( $offenders ),
				'rendar-prepublish-checks'
			),
			count( $offenders )
		),
		$offenders
	);
}

/**
 * At least one tag.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_tags( Rendar_PC_Context $context ) {
	if ( $context->tag_ids ) {
		return rendar_pc_pass(
			sprintf(
				/* translators: %d: number of tags. */
				_n( '%d tag is set.', '%d tags are set.', count( $context->tag_ids ), 'rendar-prepublish-checks' ),
				count( $context->tag_ids )
			)
		);
	}

	return rendar_pc_fail( __( 'This article has no tags.', 'rendar-prepublish-checks' ) );
}

/**
 * At least one category.
 *
 * Reads the context, which carries the editor's pending selection. Reading the
 * saved row instead would make this check unfailable: wp_publish_post() applies
 * the default category to a categoryless post before writing the status.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_categories( Rendar_PC_Context $context ) {
	if ( $context->category_ids ) {
		return rendar_pc_pass(
			sprintf(
				/* translators: %d: number of categories. */
				_n( '%d category is set.', '%d categories are set.', count( $context->category_ids ), 'rendar-prepublish-checks' ),
				count( $context->category_ids )
			)
		);
	}

	return rendar_pc_fail(
		__( 'This article has no category. WordPress will file it under the default category if you publish it like this.', 'rendar-prepublish-checks' )
	);
}

/**
 * Every required custom field, for whichever ACF field groups apply to this
 * post's type and category, has a value.
 *
 * Generic on purpose — it resolves whatever field groups ACF's own location
 * rules say apply to this post (rendar_pc_matching_field_groups(), read
 * once in the context) rather than naming particular fields. A group
 * added later, or an existing one whose location rules change, is covered
 * automatically.
 *
 * Not gated by skips_content_checks() — that flag exists for the classic-vs-
 * blocks *content-image* checks, and required-field emptiness has nothing to
 * do with content shape.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_check_required_fields( Rendar_PC_Context $context ) {
	if ( $context->missing_required_fields ) {
		return rendar_pc_fail(
			sprintf(
				/* translators: %d: number of empty required fields. */
				_n(
					'%d required custom field is empty.',
					'%d required custom fields are empty.',
					count( $context->missing_required_fields ),
					'rendar-prepublish-checks'
				),
				count( $context->missing_required_fields )
			),
			$context->missing_required_fields
		);
	}

	if ( ! $context->matched_field_groups ) {
		// ACF absent is NOT the same as "no group applies": a missing dependency
		// must never read as a silent pass, so it is reported unavailable (with the
		// reason) rather than not-applicable.
		if ( ! $context->acf_available ) {
			return rendar_pc_unavailable(
				__( 'ACF is not active, so required custom fields could not be checked.', 'rendar-prepublish-checks' )
			);
		}

		return rendar_pc_not_applicable(
			__( 'No custom field group applies to this article.', 'rendar-prepublish-checks' )
		);
	}

	return rendar_pc_pass( __( 'All required custom fields are filled.', 'rendar-prepublish-checks' ) );
}

/**
 * Message shown when the content checks stand down for classic content.
 *
 * Said out loud on purpose. A skipped check that renders as a tick is worse
 * than a failing one, because the author acts on it.
 *
 * @return string
 */
function rendar_pc_classic_skip_message() {
	return __( 'Skipped: this article\'s content is classic (not blocks).', 'rendar-prepublish-checks' );
}

/**
 * Build an offender entry for an image.
 *
 * @param array $image Image descriptor.
 * @return array
 */
function rendar_pc_image_offender( array $image ) {
	$id = (int) $image['attachment_id'];

	return array(
		'key'      => $image['key'],
		'id'       => $id,
		'label'    => $id ? rendar_pc_attachment_label( $id ) : rendar_pc_src_label( $image['src'] ),
		'src'      => $image['src'],
		'edit_url' => $id ? rendar_pc_attachment_edit_url( $id ) : '',
	);
}

/**
 * Stored width of an attachment, or null when it has none.
 *
 * @param int $attachment_id Attachment ID.
 * @return int|null
 */
function rendar_pc_attachment_width( $attachment_id ) {
	$meta = wp_get_attachment_metadata( $attachment_id );

	if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
		return null;
	}

	return (int) $meta['width'];
}

/**
 * A short human label for an attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function rendar_pc_attachment_label( $attachment_id ) {
	$attachment = get_post( $attachment_id );

	if ( ! $attachment instanceof WP_Post ) {
		/* translators: %d: attachment ID. */
		return sprintf( __( 'Image #%d', 'rendar-prepublish-checks' ), (int) $attachment_id );
	}

	$title = trim( (string) $attachment->post_title );

	if ( '' !== $title ) {
		return $title;
	}

	return rendar_pc_src_label( wp_get_attachment_url( $attachment_id ) );
}

/**
 * A short human label derived from an image URL.
 *
 * @param string $src Image URL.
 * @return string
 */
function rendar_pc_src_label( $src ) {
	$path = wp_parse_url( (string) $src, PHP_URL_PATH );
	$name = $path ? wp_basename( $path ) : '';

	return '' !== $name ? $name : __( 'Untitled image', 'rendar-prepublish-checks' );
}

/**
 * Edit link for an attachment, for users who may edit it.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function rendar_pc_attachment_edit_url( $attachment_id ) {
	if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
		return '';
	}

	$url = get_edit_post_link( $attachment_id, 'raw' );

	return $url ? $url : '';
}
