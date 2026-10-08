<?php
/**
 * Image discovery, and the override token's normalized form.
 *
 * Split out of the context class so each file holds one kind of thing, which is
 * also what WordPress' own coding standard asks for.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extract every image in the content, block or classic.
 *
 * Discovery works on rendered markup rather than block attributes, which keeps
 * one code path across core/image, core/gallery, core/media-text, core/cover,
 * core/html and raw classic HTML — all of which end up as an <img> in the
 * block's own innerHTML. Block attributes are consulted only where the markup
 * cannot answer (an attachment ID that carries no wp-image-N class), and a
 * block that owns knowledge the markup cannot express supplies it through the
 * `rendar_prepublish_checks_block_images` filter.
 *
 * @param string $content Post content.
 * @return array[] List of image descriptors.
 */
function rendar_pc_extract_images( $content ) {
	$content = (string) $content;

	if ( '' === trim( $content ) ) {
		return array();
	}

	$images = array();

	rendar_pc_walk_blocks( parse_blocks( $content ), $images );

	return rendar_pc_dedupe_images( $images );
}

/**
 * Collapse repeats of the same photo.
 *
 * The same photo used twice is one obligation, and the panel listing it twice
 * would invite two acknowledgments for one decision. First occurrence wins,
 * which is worth stating out loud now that a block can supply a credit of its
 * own: a photo that appears in a credited gallery and again in an uncredited
 * one is judged on the first appearance. It is credited somewhere in the
 * article, the check is a warning either way, and the alternative — reporting
 * one photograph twice with two different answers — is worse.
 *
 * @param array[] $images Descriptors, in document order.
 * @return array[]
 */
function rendar_pc_dedupe_images( array $images ) {
	$seen  = array();
	$final = array();

	foreach ( $images as $image ) {
		if ( isset( $seen[ $image['key'] ] ) ) {
			continue;
		}

		$seen[ $image['key'] ] = true;
		$final[]               = $image;
	}

	return $final;
}

/**
 * Recursively collect images from a parsed block tree.
 *
 * @param array   $blocks Parsed blocks.
 * @param array[] $images Accumulator, by reference.
 * @return void
 */
function rendar_pc_walk_blocks( array $blocks, array &$images ) {
	foreach ( $blocks as $block ) {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

		$block_images = '' !== trim( $html ) ? rendar_pc_images_from_html( $html ) : array();

		/**
		 * Filters the images one block contributes to the evaluation.
		 *
		 * Exists so a block that knows something its saved markup cannot say
		 * can say it here, in the one place both the live panel and the publish
		 * gate read from. A typical use is a gallery block supplying two facts:
		 * which attachment each slide is (when its saved markup carries no
		 * `wp-image-N` class, the markup scan cannot tell), and the block's default photo credit, which is a block
		 * attribute rather than anything an <img> tag can express.
		 *
		 * A callback returns descriptors; missing fields are filled in by
		 * rendar_pc_normalize_image() afterwards, so returning just
		 * `attachment_id`, `alt`, `src` and `caption` is enough. Returning an
		 * empty array removes the block's images from the evaluation entirely,
		 * which is a real decision and not a way to silence a check by accident.
		 *
		 * @param array[] $block_images Descriptors discovered in the block's own markup.
		 * @param array   $block        The parsed block, attributes included.
		 */
		$block_images = apply_filters( 'rendar_prepublish_checks_block_images', $block_images, $block );

		foreach ( (array) $block_images as $image ) {
			$image = rendar_pc_normalize_image( $image, $name, $block );

			if ( null !== $image ) {
				$images[] = $image;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			rendar_pc_walk_blocks( $block['innerBlocks'], $images );
		}
	}
}

/**
 * Bring a descriptor up to the full shape every consumer assumes.
 *
 * Applied to markup-discovered and filter-supplied descriptors alike, so a
 * third-party callback cannot hand the checks a half-built array and turn a
 * missing key into a warning in someone's error log.
 *
 * @param mixed  $image Descriptor candidate.
 * @param string $name  Block name, empty for classic content.
 * @param array  $block Parsed block.
 * @return array|null Normalized descriptor, or null when it is not one.
 */
function rendar_pc_normalize_image( $image, $name, array $block ) {
	if ( ! is_array( $image ) ) {
		return null;
	}

	$image = array_merge(
		array(
			'attachment_id'     => 0,
			'src'               => '',
			'alt'               => '',
			'caption'           => '',
			'markup_decorative' => false,
			'block_name'        => '',
			'key'               => '',
		),
		$image
	);

	// Block attributes are arbitrary JSON, so a descriptor can arrive carrying
	// an array where a scalar belongs. Casting that would emit a notice and store
	// the literal "Array" (or, for absint(), the nonsense value 1); discarding it
	// is the honest answer, and this is the one choke point every descriptor
	// passes through. EVERY scalar field is guarded, not just the obvious ones:
	// an array `attachment_id` must not become 1, and an array `key` must not
	// reach the dedupe map (where it would be an illegal array offset).
	$image['attachment_id']     = is_scalar( $image['attachment_id'] ) ? absint( $image['attachment_id'] ) : 0;
	$image['src']               = rendar_pc_scalar_string( $image['src'] );
	$image['alt']               = trim( rendar_pc_scalar_string( $image['alt'] ) );
	$image['caption']           = rendar_pc_scalar_string( $image['caption'] );
	// Only a real boolean can acknowledge an image as decorative. In
	// particular, (bool) array/object values are true, which would let a
	// malformed block-image descriptor bypass the alt-text check.
	$image['markup_decorative'] = is_bool( $image['markup_decorative'] ) ? $image['markup_decorative'] : false;
	$image['block_name']        = rendar_pc_scalar_string( $image['block_name'] );
	$image['key']               = rendar_pc_scalar_string( $image['key'] );

	if ( '' === $image['block_name'] ) {
		$image['block_name'] = $name ? $name : 'classic';
	}

	if ( ! $image['attachment_id'] ) {
		$image['attachment_id'] = rendar_pc_attachment_id_from_block( $block );
	}

	if ( '' === $image['key'] ) {
		$image['key'] = rendar_pc_image_key( $image );
	}

	return $image;
}

/**
 * A value as a string, or an empty string when it is not one.
 *
 * @param mixed $value Value.
 * @return string
 */
function rendar_pc_scalar_string( $value ) {
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Is this caption nothing but whitespace?
 *
 * `trim()` alone strips ASCII whitespace only. The gallery block resolves the
 * same question in JavaScript, where String.prototype.trim() strips the Unicode
 * set — so a caption of non-breaking spaces would read as blank there and as a
 * real credit here, and this check would pass a photograph the page shows
 * uncaptioned. The character set is exactly ECMA-262's WhiteSpace +
 * LineTerminator, so that the check and the page agree: \p{Z}, the \x09-\x0D
 * control range, and U+FEFF. Neither \p{C} nor \s is used — \p{C} swallows
 * U+200B and \s matches U+0085/U+180E on some PCRE2 builds, and JavaScript
 * strips none of the three.
 *
 * @param mixed $value Caption value.
 * @return bool
 */
function rendar_pc_caption_is_blank( $value ) {
	if ( ! is_scalar( $value ) ) {
		return true;
	}

	$stripped = preg_replace( '/[\p{Z}\x09-\x0D\x{FEFF}]+/u', '', (string) $value );

	// preg_replace returns null on invalid UTF-8; fall back to the ASCII answer
	// rather than silently discarding a caption that cannot be represented.
	return null === $stripped ? '' === trim( (string) $value ) : '' === $stripped;
}

/**
 * Pull image descriptors out of a fragment of HTML.
 *
 * Uses WP_HTML_Tag_Processor rather than a regex because attribute order,
 * quoting and stray angle brackets in captions all break the regex version, and
 * a missed image here reads to an author as the check being broken.
 *
 * @param string $html HTML fragment.
 * @return array[]
 */
function rendar_pc_images_from_html( $html ) {
	$found = array();

	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $found;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		$src   = $processor->get_attribute( 'src' );
		$alt   = $processor->get_attribute( 'alt' );
		$role  = $processor->get_attribute( 'role' );
		$aria  = $processor->get_attribute( 'aria-hidden' );
		$class = $processor->get_attribute( 'class' );

		$attachment_id = 0;

		if ( is_string( $class ) && preg_match( '/wp-image-(\d+)/', $class, $matches ) ) {
			$attachment_id = (int) $matches[1];
		}

		$found[] = array(
			'attachment_id'     => $attachment_id,
			'src'               => is_string( $src ) ? $src : '',
			'alt'               => is_string( $alt ) ? trim( $alt ) : '',
			// A credit the content itself supplies, independent of the
			// attachment's own caption. Nothing in an <img> tag carries one, so
			// the markup scan always leaves it empty; it is filled in by the
			// rendar_prepublish_checks_block_images filter above.
			'caption'           => '',
			'markup_decorative' => ( 'presentation' === strtolower( (string) $role ) || 'none' === strtolower( (string) $role ) || 'true' === strtolower( (string) $aria ) ),
			'block_name'        => '',
			'key'               => '',
		);
	}

	return $found;
}

/**
 * Attachment ID from a block's own attributes.
 *
 * Only consulted when the markup carries no wp-image-N class — a core/image
 * inserted from a URL, or a media-text whose image class was stripped.
 *
 * @param array $block Parsed block.
 * @return int
 */
function rendar_pc_attachment_id_from_block( array $block ) {
	$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

	foreach ( array( 'id', 'mediaId' ) as $key ) {
		if ( ! empty( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
			return (int) $attrs[ $key ];
		}
	}

	return 0;
}

/**
 * Stable key for an image, used to attach decorative acknowledgments.
 *
 * Keyed on the attachment where there is one, so re-ordering a post or
 * regenerating its markup does not silently discard the author's decision.
 * A source hash is the fallback for images with no attachment behind them.
 *
 * @param array $image Image descriptor.
 * @return string
 */
function rendar_pc_image_key( array $image ) {
	if ( ! empty( $image['attachment_id'] ) ) {
		return 'id:' . (int) $image['attachment_id'];
	}

	return 'src:' . md5( (string) $image['src'] );
}

/**
 * Normalize a stored or incoming override token.
 *
 * @param mixed $raw Raw value.
 * @return array|null Normalized token, or null when absent or malformed.
 */
function rendar_pc_normalize_override( $raw ) {
	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		$raw     = is_array( $decoded ) ? $decoded : null;
	}

	if ( ! is_array( $raw ) ) {
		return null;
	}

	$reason = isset( $raw['reason'] ) ? trim( wp_strip_all_tags( (string) $raw['reason'] ) ) : '';
	$checks = isset( $raw['checks'] ) && is_array( $raw['checks'] )
		? array_values( array_unique( array_filter( array_map( 'sanitize_key', $raw['checks'] ) ) ) )
		: array();

	if ( '' === $reason || ! $checks ) {
		return null;
	}

	return array(
		'reason' => $reason,
		'checks' => $checks,
	);
}
