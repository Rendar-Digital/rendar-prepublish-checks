<?php
/**
 * Example site policy — a NON-SHIPPED fixture site extension.
 *
 * Neutral core ships every built-in check at WARNING and no editorial policy of
 * its own. This file shows how a site layers its own policy on top using ONLY
 * the public v1 extension contract (docs/API.md) — the three filters
 * `rendar_prepublish_checks_definitions`, `rendar_prepublish_checks_block_images`
 * and `rendar_prepublish_checks_settings_sections`. Nothing here is required by
 * the plugin; core runs without it.
 *
 * It lives under tests/ and is therefore never packaged (bin/build-zip ships
 * only `<slug>.php`, README.md, CHANGELOG.md, inc/* and assets/*, and rejects
 * any tests/docs path). In a real deployment the equivalent would be an
 * mu-plugin. tests/test-fixture-public-api.php proves it touches no private
 * core symbol; tests/test-save-log-setting.php exercises its settings section.
 *
 * What it demonstrates:
 *
 *   - A severity policy: which checks block and which only advise, set on the
 *     definitions filter (descriptor-level severity flows through because core
 *     pins no stored severity).
 *   - Three site-specific editorial checks registered from outside core:
 *     caption-as-photo-credit (`image-credit`) and the default-category
 *     ancestry pair (`default-category-only`, `category-ancestors`).
 *   - A block-image adapter for a third-party gallery block, on the public
 *     block-images filter.
 *   - A settings section of its own, on the settings-sections slot.
 *
 * Site policy VALUES — the featured-image width thresholds and the
 * publishing-failure recipient — are deliberately NOT set here: there is no
 * value-injection filter in the v1 contract. They are stored settings an
 * administrator configures on the settings screen.
 *
 * @package Example_Policy_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply the site's check set and severity policy.
 *
 * Rebuilds the registry in the site's preferred order, inserting the three
 * site-specific editorial checks and stamping every check with the site's
 * severity. Core ships all built-ins at WARNING and pins no stored severity, so
 * these descriptor severities are what core resolves.
 *
 * @param array[] $checks Core's registered checks, keyed by ID.
 * @return array[]
 */
function example_pp_definitions( $checks ) {
	// Site severity policy, by check ID.
	$severity = array(
		'featured-image'             => 'error',
		'featured-image-min-width'   => 'error',
		'featured-image-ideal-width' => 'warning',
		'image-alt-text'             => 'error',
		'image-credit'               => 'warning',
		'post-tags'                  => 'error',
		'post-categories'            => 'error',
		'default-category-only'      => 'warning',
		'category-ancestors'         => 'error',
		'required-custom-fields'     => 'error',
	);

	// The three site-specific editorial checks.
	$added = array(
		'image-credit'          => array(
			'label'       => __( 'Photo credits', 'rendar-prepublish-checks' ),
			'description' => __( 'Every photo in the article carries a caption crediting the photographer.', 'rendar-prepublish-checks' ),
			'severity'    => 'warning',
			'callback'    => 'example_pp_check_image_credit',
		),
		'default-category-only' => array(
			'label'       => __( 'Section category', 'rendar-prepublish-checks' ),
			'description' => __( 'The article is filed under something more specific than the default category.', 'rendar-prepublish-checks' ),
			'severity'    => 'warning',
			'callback'    => 'example_pp_check_default_category_only',
		),
		'category-ancestors'    => array(
			'label'       => __( 'Parent categories', 'rendar-prepublish-checks' ),
			'description' => __( 'Every selected subcategory also has its parent categories selected.', 'rendar-prepublish-checks' ),
			'severity'    => 'error',
			'callback'    => 'example_pp_check_category_ancestors',
		),
	);

	$checks = is_array( $checks ) ? $checks : array();

	// Merge the added checks in, then re-emit in the site's order.
	$merged = array_merge( $checks, $added );
	$order  = array_keys( $severity );
	$result = array();

	foreach ( $order as $id ) {
		if ( ! isset( $merged[ $id ] ) ) {
			continue;
		}

		$check = $merged[ $id ];

		if ( isset( $severity[ $id ] ) ) {
			$check['severity'] = $severity[ $id ];
		}

		$result[ $id ] = $check;
	}

	// Anything core or another extension registered that is not in the site's
	// ordering is appended rather than dropped.
	foreach ( $merged as $id => $check ) {
		if ( ! isset( $result[ $id ] ) ) {
			$result[ $id ] = $check;
		}
	}

	return $result;
}
add_filter( 'rendar_prepublish_checks_definitions', 'example_pp_definitions' );

/*
 * ---------------------------------------------------------------------------
 * The three site-specific editorial checks. They use only the public result
 * and image helpers in docs/API.md. The dynamic-token credit exemption is
 * editorial policy, owned by this fixture.
 * ---------------------------------------------------------------------------
 */

/**
 * Every content photo carries a credit.
 *
 * A credit on this site is free text in the attachment's caption — there is no
 * structured field, and the caption is also used for genuine captions. Nothing
 * here can tell a credit from a caption, which is exactly why this check is a
 * warning: it surfaces an image nobody has written anything about, and leaves
 * the licensing judgment to a person.
 *
 * Two sources, in the order the front end resolves them: the attachment's own
 * caption first, then whatever credit the content itself supplies for that
 * image (a gallery block's default photo credit, delivered by the
 * `rendar_prepublish_checks_block_images` filter). The order is not arbitrary —
 * it should match the precedence the gallery renders with, so what this check
 * reads is what a reader will see under the photograph rather than an
 * approximation of it.
 *
 * One narrow image shape never reaches caption lookup: an attachment-less
 * GenerateBlocks descriptor whose complete trimmed `src` is one dynamic tag
 * (see example_pp_image_src_is_dynamic_tag()). It is a render-time token,
 * not a captionable attachment in this article. Decorative acknowledgments
 * remain an alt-text-only decision: the panel's "Decorative — no alt needed"
 * control must not silently clear this check. The documented override is the
 * escape hatch for a credit check that genuinely needs one.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function example_pp_check_image_credit( Rendar_PC_Context $context ) {
	if ( $context->skips_content_checks() ) {
		return rendar_pc_not_applicable( rendar_pc_classic_skip_message() );
	}

	if ( ! $context->images ) {
		return rendar_pc_pass( __( 'This article has no images in its content.', 'rendar-prepublish-checks' ) );
	}

	$offenders = array();
	$unknown   = 0;

	foreach ( $context->images as $image ) {
		// Out of scope only when this is the exact attachment-less GenerateBlocks
		// dynamic-tag shape. Anything else, including markup or panel decorative
		// acknowledgments, remains a credit obligation.
		if ( example_pp_image_src_is_dynamic_tag( $image['src'], $image['attachment_id'], $image['block_name'] ) ) {
			continue;
		}

		$caption = $image['attachment_id']
			? example_pp_attachment_caption( $image['attachment_id'] )
			: '';

		if ( rendar_pc_caption_is_blank( $caption ) ) {
			$caption = isset( $image['caption'] ) ? $image['caption'] : '';
		}

		if ( ! rendar_pc_caption_is_blank( $caption ) ) {
			continue;
		}

		if ( ! $image['attachment_id'] ) {
			// An image with no attachment behind it and no credit in the content
			// has no caption to read at all. Count it, report it, and do not
			// pretend it passed.
			++$unknown;
			continue;
		}

		$offenders[] = rendar_pc_image_offender( $image );
	}

	if ( ! $offenders && ! $unknown ) {
		return rendar_pc_pass( __( 'Every photo carries a caption.', 'rendar-prepublish-checks' ) );
	}

	$parts = array();

	if ( $offenders ) {
		$parts[] = sprintf(
			/* translators: %d: number of photos. */
			_n(
				'%d photo has no caption crediting the photographer.',
				'%d photos have no caption crediting the photographer.',
				count( $offenders ),
				'rendar-prepublish-checks'
			),
			count( $offenders )
		);
	}

	if ( $unknown ) {
		$parts[] = sprintf(
			/* translators: %d: number of images. */
			_n(
				'%d image is not in the media library, so its credit could not be checked.',
				'%d images are not in the media library, so their credits could not be checked.',
				$unknown,
				'rendar-prepublish-checks'
			),
			$unknown
		);
	}

	return rendar_pc_fail( implode( ' ', $parts ), $offenders );
}

/**
 * The default category is not the only category.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function example_pp_check_default_category_only( Rendar_PC_Context $context ) {
	$default_id = (int) get_option( 'default_category', 0 );

	if ( ! $default_id ) {
		return rendar_pc_not_applicable( __( 'This site has no default category configured.', 'rendar-prepublish-checks' ) );
	}

	if ( ! $context->category_ids ) {
		// Handled by the categories check; flagging it here too would double-report.
		return rendar_pc_not_applicable( __( 'No category is set yet.', 'rendar-prepublish-checks' ) );
	}

	if ( array( $default_id ) !== array_map( 'intval', array_values( $context->category_ids ) ) ) {
		return rendar_pc_pass( __( 'The article is filed under a section category.', 'rendar-prepublish-checks' ) );
	}

	$term = get_term( $default_id, 'category' );
	$name = ( $term instanceof WP_Term ) ? $term->name : __( 'the default category', 'rendar-prepublish-checks' );

	return rendar_pc_fail(
		sprintf(
			/* translators: %s: category name. */
			__( '%s is the only category set. Consider filing this under a more specific section as well.', 'rendar-prepublish-checks' ),
			$name
		)
	);
}

/**
 * Every selected child category carries its complete parent chain.
 *
 * WordPress stores a post's categories as a flat list. It does not infer that
 * assigning a child also assigns its parent, so this requirement is explicit.
 * All ancestors are checked, rather than just the direct parent: a future
 * third-level category cannot quietly leave its top-level section unassigned.
 *
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function example_pp_check_category_ancestors( Rendar_PC_Context $context ) {
	if ( ! $context->category_ids ) {
		// The categories check owns this failure. Reporting it here too would make
		// one omission look like two independent problems.
		return rendar_pc_not_applicable( __( 'No category is set yet.', 'rendar-prepublish-checks' ) );
	}

	$selected = array_fill_keys( array_map( 'intval', $context->category_ids ), true );
	$missing  = array();

	foreach ( $context->category_ids as $category_id ) {
		foreach ( get_ancestors( $category_id, 'category', 'taxonomy' ) as $ancestor_id ) {
			$ancestor_id = (int) $ancestor_id;

			if ( ! isset( $selected[ $ancestor_id ] ) ) {
				$missing[ $ancestor_id ] = true;
			}
		}
	}

	if ( ! $missing ) {
		return rendar_pc_pass( __( 'Every selected subcategory has its parent categories selected.', 'rendar-prepublish-checks' ) );
	}

	$offenders = array();

	foreach ( array_keys( $missing ) as $category_id ) {
		$term  = get_term( $category_id, 'category' );
		$label = $term instanceof WP_Term
			? $term->name
			: sprintf(
				/* translators: %d: category ID. */
				__( 'Category #%d', 'rendar-prepublish-checks' ),
				$category_id
			);

		$offenders[] = array(
			'key'   => 'term:' . $category_id,
			'id'    => $category_id,
			'label' => $label,
		);
	}

	return rendar_pc_fail(
		sprintf(
			/* translators: %d: number of missing parent categories. */
			_n(
				'%d parent category is missing.',
				'%d parent categories are missing.',
				count( $offenders ),
				'rendar-prepublish-checks'
			),
			count( $offenders )
		),
		$offenders
	);
}

/**
 * An attachment's caption.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function example_pp_attachment_caption( $attachment_id ) {
	$attachment = get_post( $attachment_id );

	return $attachment instanceof WP_Post ? (string) $attachment->post_excerpt : '';
}

/**
 * Exempt only a complete GB dynamic token without a media attachment from
 * this site's credit policy. A query token, static prefix or suffix, classic
 * image, or attachment-backed image is still subject to credit checking.
 *
 * @param string $src Saved image URL.
 * @param int    $attachment_id Attachment ID, if any.
 * @param string $block_name Owning block name.
 * @return bool
 */
function example_pp_image_src_is_dynamic_tag( $src, $attachment_id = 0, $block_name = '' ) {
	if ( ! is_string( $src ) || 0 !== (int) $attachment_id || 0 !== strpos( (string) $block_name, 'generateblocks/' ) ) {
		return false;
	}

	return 1 === preg_match( '/^\{\{[a-z0-9_]+(?:\s[^{}]*)?\}\}$/i', trim( $src ) );
}

/*
 * ---------------------------------------------------------------------------
 * Gallery block-image adapter for a hypothetical `example/gallery` block whose
 * images live in a block attribute, on the public block-images filter.
 * Supplies the two facts the markup scan cannot know: which attachment each
 * slide is, and the gallery's default photo credit. Blank-caption detection
 * uses core's public rendar_pc_caption_is_blank(), which is Unicode-aware.
 * ---------------------------------------------------------------------------
 */

add_filter( 'rendar_prepublish_checks_block_images', 'example_pp_gallery_block_images', 10, 2 );

/**
 * Describe a gallery block's images to the pre-publish checks.
 *
 * @param array[] $images Descriptors prepublish found in the block's markup.
 * @param array   $block  Parsed block.
 * @return array[]
 */
function example_pp_gallery_block_images( $images, $block ) {
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

	if ( 'example/gallery' !== $name ) {
		return $images;
	}

	$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$stored = isset( $attrs['images'] ) && is_array( $attrs['images'] ) ? $attrs['images'] : array();

	if ( ! $stored ) {
		// A gallery with no images attribute is either empty or something we do
		// not recognise. Either way the markup scan's answer is no worse than a
		// guess made here.
		return $images;
	}

	// Whitespace-only is not a credit. rendar_pc_caption_is_blank() treats
	// Unicode whitespace as blank too; a gallery's own renderer should agree.
	$default = isset( $attrs['defaultCaption'] ) && is_scalar( $attrs['defaultCaption'] )
		? (string) $attrs['defaultCaption']
		: '';
	$default = rendar_pc_caption_is_blank( $default ) ? '' : $default;

	$described = array();

	foreach ( $stored as $image ) {
		if ( ! is_array( $image ) ) {
			continue;
		}

		$described[] = array(
			'attachment_id' => isset( $image['id'] ) ? absint( $image['id'] ) : 0,
			'src'           => isset( $image['url'] ) && is_scalar( $image['url'] ) ? (string) $image['url'] : '',
			// The stored snapshot, which is also exactly what the saved
			// fallback markup carries — so the alt check reaches the same
			// verdict it reached before this filter existed.
			'alt'           => isset( $image['alt'] ) && is_scalar( $image['alt'] ) ? (string) $image['alt'] : '',
			// Deliberately NOT the stored per-image caption snapshot. Where an
			// attachment resolves, prepublish reads that attachment's live
			// caption itself, which is the value the front end renders; the
			// snapshot can be stale and would credit a photograph on the
			// strength of a caption that has since been deleted. What this
			// carries is only the part prepublish has no other way to know.
			'caption'       => $default,
			'block_name'    => $name,
		);
	}

	return $described ? $described : $images;
}

/*
 * ---------------------------------------------------------------------------
 * Save Log settings section — an example companion feature rendered through
 * the public settings-sections slot. It owns its own option, default and
 * sanitising, and the core settings screen only offers the slot, so the
 * settings-sections contract has a real consumer.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'EXAMPLE_PP_SAVE_LOG_OPTION' ) ) {
	define( 'EXAMPLE_PP_SAVE_LOG_OPTION', 'example_save_log_cron_status' );
}

add_action( 'rendar_prepublish_checks_settings_sections', 'example_pp_save_log_section' );
add_action( 'admin_init', 'example_pp_register_save_log_setting' );

/** Register the Save Log's own option with the shared settings form group. */
function example_pp_register_save_log_setting() {
	register_setting(
		'rendar_pc',
		EXAMPLE_PP_SAVE_LOG_OPTION,
		array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => 'example_pp_sanitize_save_log_setting',
		)
	);
}

/** Normalize checkbox input; an absent or false-like value disables logging. */
function example_pp_sanitize_save_log_setting( $value ) {
	return '1' === (string) $value ? '1' : '0';
}

/**
 * The Save Log section on the Pre-Publish Checks settings screen.
 *
 * @return void
 */
function example_pp_save_log_section() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$on = '0' !== (string) get_option( EXAMPLE_PP_SAVE_LOG_OPTION, '1' );
	?>
	<h2 id="example-pp-save-log"><?php esc_html_e( 'Save Log (diagnostics)', 'rendar-prepublish-checks' ); ?></h2>
	<p class="description" style="max-width:46em">
		<?php esc_html_e( 'The Save Log records editorial saves so a "my post disappeared" report can be answered from data. This setting belongs to the Save Log, not to the pre-publish checks.', 'rendar-prepublish-checks' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Scheduled publishing', 'rendar-prepublish-checks' ); ?></th>
			<td>
				<label>
					<input type="hidden" value="0" name="<?php echo esc_attr( EXAMPLE_PP_SAVE_LOG_OPTION ); ?>" />
					<input type="checkbox" value="1" name="<?php echo esc_attr( EXAMPLE_PP_SAVE_LOG_OPTION ); ?>" <?php checked( $on ); ?> />
					<?php esc_html_e( 'Also record status changes made by scheduled tasks (a scheduled post going live, or being taken back to pending). Posts only.', 'rendar-prepublish-checks' ); ?>
				</label>
			</td>
		</tr>
	</table>
	<?php
}
