<?php
/**
 * The evaluation context: one normalized snapshot of a post, built the same way
 * whether it comes from the database or from an unsaved editor payload.
 *
 * This class is the reason the advisory panel and the publish gate cannot
 * disagree. Both call Rendar_PC_Context::build() with the same override
 * shape; the checks never see where the values came from. Any future consumer
 * that reaches around this class reintroduces exactly the drift it exists to
 * prevent.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalized snapshot of the post under evaluation.
 */
class Rendar_PC_Context {

	/**
	 * Post ID. Zero for a post that has never been saved.
	 *
	 * @var int
	 */
	public $post_id = 0;

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public $post_type = 'post';

	/**
	 * Status currently stored in the database.
	 *
	 * @var string
	 */
	public $current_status = 'auto-draft';

	/**
	 * Status being evaluated for — the status the save is trying to reach.
	 *
	 * @var string
	 */
	public $target_status = 'draft';

	/**
	 * Whether this post has ever been published.
	 *
	 * @var bool
	 */
	public $was_published = false;

	/**
	 * Whether this post predates the checks being switched on.
	 *
	 * @var bool
	 */
	public $predates_checks = false;

	/**
	 * Raw post content under evaluation.
	 *
	 * @var string
	 */
	public $content = '';

	/**
	 * Whether the content contains any real block beyond core/freeform.
	 *
	 * @var bool
	 */
	public $has_real_blocks = false;

	/**
	 * Featured image attachment ID, or 0.
	 *
	 * @var int
	 */
	public $featured_id = 0;

	/**
	 * Category term IDs.
	 *
	 * @var int[]
	 */
	public $category_ids = array();

	/**
	 * Tag term IDs.
	 *
	 * @var int[]
	 */
	public $tag_ids = array();

	/**
	 * Discovered content images.
	 *
	 * @var array[]
	 */
	public $images = array();

	/**
	 * Whether ACF is active at all. Distinguishes "ACF present, no group applies"
	 * (not_applicable) from "ACF absent, cannot check" (unavailable), so a missing
	 * dependency never reads as a silent pass.
	 *
	 * @var bool
	 */
	public $acf_available = false;

	/**
	 * ACF field groups whose location rules match this post.
	 *
	 * @var array[]
	 */
	public $matched_field_groups = array();

	/**
	 * Required ACF fields, across every matched group, that are currently
	 * empty. Offender entries, rendar_pc_field_offender()-shaped.
	 *
	 * @var array[]
	 */
	public $missing_required_fields = array();

	/**
	 * Image keys the author has acknowledged as decorative.
	 *
	 * @var string[]
	 */
	public $decorative_keys = array();

	/**
	 * The one-shot override token accompanying this save, if any.
	 *
	 * @var array|null
	 */
	public $override = null;

	/**
	 * Build a context.
	 *
	 * Every field falls back to the saved post when the override is absent,
	 * which is what makes one builder serve both the REST gate (where the
	 * prepared post carries only the fields the request changed) and the
	 * advisory endpoint (which sends the editor's unsaved state in full).
	 *
	 * Recognized override keys: post_type, post_status, post_content,
	 * featured_media, categories, tags, meta.
	 *
	 * @param int   $post_id   Post ID, 0 for an unsaved post.
	 * @param array $overrides Values that take precedence over the saved post.
	 * @return Rendar_PC_Context
	 */
	public static function build( $post_id, array $overrides = array() ) {
		$context = new self();
		$post    = $post_id ? get_post( $post_id ) : null;

		$context->post_id        = $post ? (int) $post->ID : (int) $post_id;
		$context->post_type      = self::pick( $overrides, 'post_type', $post ? $post->post_type : 'post' );
		$context->current_status = $post ? $post->post_status : 'auto-draft';
		$context->target_status  = self::pick( $overrides, 'post_status', $context->current_status );
		$context->content        = (string) self::pick( $overrides, 'post_content', $post ? $post->post_content : '' );

		$context->has_real_blocks = self::content_has_real_blocks( $context->content );

		$context->featured_id = (int) self::pick(
			$overrides,
			'featured_media',
			$context->post_id ? (int) get_post_thumbnail_id( $context->post_id ) : 0
		);

		$context->category_ids = self::term_ids( $overrides, 'categories', $context->post_id, 'category' );
		$context->tag_ids      = self::term_ids( $overrides, 'tags', $context->post_id, 'post_tag' );

		$context->was_published   = self::resolve_was_published( $post, $context );
		$context->predates_checks = self::resolve_predates_checks( $post );

		$meta = isset( $overrides['meta'] ) && is_array( $overrides['meta'] ) ? $overrides['meta'] : array();

		$context->decorative_keys = self::resolve_decorative_keys( $meta, $context->post_id );
		$context->override        = self::resolve_override( $meta, $context->post_id );

		$context->images = rendar_pc_extract_images( $context->content );

		$context->acf_available = function_exists( 'acf_get_field_groups' )
			&& function_exists( 'acf_get_fields' ) && function_exists( 'get_field' );

		$context->matched_field_groups    = $context->acf_available ? rendar_pc_matching_field_groups(
			$context->post_type,
			$context->category_ids,
			$context->tag_ids,
			$context->post_id
		) : array();
		$context->missing_required_fields = rendar_pc_missing_required_fields(
			$context->matched_field_groups,
			$context->post_id,
			$meta
		);

		return $context;
	}

	/**
	 * Pick an override value, falling back when the key is absent.
	 *
	 * Absent means "not supplied", not "empty" — an author clearing the featured
	 * image sends 0, and that must not fall back to the saved value.
	 *
	 * @param array  $overrides Override map.
	 * @param string $key       Key to read.
	 * @param mixed  $fallback  Value when the key is absent.
	 * @return mixed
	 */
	private static function pick( array $overrides, $key, $fallback ) {
		return array_key_exists( $key, $overrides ) && null !== $overrides[ $key ]
			? $overrides[ $key ]
			: $fallback;
	}

	/**
	 * Resolve term IDs from the override, else from the saved post.
	 *
	 * The category case is why this exists at all: wp_publish_post() applies the
	 * default category to a categoryless post before it writes the row, so a
	 * check that reads saved terms after a publish can never fail. The editor's
	 * pending selection is the only state where "no category" is observable.
	 *
	 * @param array  $overrides Override map.
	 * @param string $key       Override key.
	 * @param int    $post_id   Post ID.
	 * @param string $taxonomy  Taxonomy name.
	 * @return int[]
	 */
	private static function term_ids( array $overrides, $key, $post_id, $taxonomy ) {
		if ( array_key_exists( $key, $overrides ) && is_array( $overrides[ $key ] ) ) {
			return array_values( array_unique( array_filter( array_map( 'absint', $overrides[ $key ] ) ) ) );
		}

		if ( ! $post_id ) {
			return array();
		}

		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		return is_wp_error( $terms ) ? array() : array_map( 'absint', $terms );
	}

	/**
	 * Has this post ever been published?
	 *
	 * Drives the rule that an already-live post is never blocked by a check it
	 * was already violating when it went live — otherwise turning the plugin on
	 * would make routine edits to 12,000 existing articles impossible.
	 *
	 * The stored marker is authoritative once present. For posts that predate
	 * the plugin there is no marker, so a currently-public status stands in for
	 * one.
	 *
	 * @param WP_Post|null          $post    Saved post, if any.
	 * @param Rendar_PC_Context $context Context being built.
	 * @return bool
	 */
	private static function resolve_was_published( $post, Rendar_PC_Context $context ) {
		if ( ! $post ) {
			return false;
		}

		if ( get_post_meta( $post->ID, RENDAR_PC_META_FIRST_PUBLISHED, true ) ) {
			return true;
		}

		return in_array( $context->current_status, array( 'publish', 'private' ), true );
	}

	/**
	 * Was this post created before the checks were switched on?
	 *
	 * This is the honest form of "don't apply this to the old stuff". Keying
	 * the exemption on classic content instead looks equivalent and is not:
	 * has_blocks( '' ) is false, so every brand-new article would be exempt
	 * until its author typed something, and an article written into a classic
	 * block would escape every check permanently. Age is
	 * the property that actually distinguishes the archive from new work, and it
	 * cannot be acquired by accident.
	 *
	 * @param WP_Post|null $post Saved post, if any.
	 * @return bool
	 */
	private static function resolve_predates_checks( $post ) {
		if ( ! $post ) {
			return false;
		}

		$watermark = rendar_pc_watermark();

		if ( ! $watermark ) {
			return false;
		}

		$created = $post->post_date_gmt;

		// A draft's post_date_gmt is zeroes — core only fills it in when the post
		// is scheduled or published. That is typically nearly every draft and
		// pending article, i.e. the entire population this rule exists to
		// exempt, so reading post_date_gmt alone would make the whole rule a
		// no-op. post_date is always populated.
		if ( ! $created || '0000-00-00 00:00:00' === $created ) {
			$created = ( $post->post_date && '0000-00-00 00:00:00' !== $post->post_date )
				? get_gmt_from_date( $post->post_date )
				: '';
		}

		if ( ! $created ) {
			return false;
		}

		return strtotime( $created . ' UTC' ) < strtotime( $watermark . ' UTC' );
	}

	/**
	 * Decorative acknowledgments, from the incoming meta or the saved row.
	 *
	 * @param array $meta    Meta supplied with the request.
	 * @param int   $post_id Post ID.
	 * @return string[]
	 */
	private static function resolve_decorative_keys( array $meta, $post_id ) {
		if ( array_key_exists( RENDAR_PC_META_DECORATIVE, $meta ) ) {
			$raw = $meta[ RENDAR_PC_META_DECORATIVE ];
		} elseif ( $post_id ) {
			$raw = get_post_meta( $post_id, RENDAR_PC_META_DECORATIVE, true );
		} else {
			$raw = array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $raw ) ) ) );
	}

	/**
	 * The active override token, from the incoming meta or the saved row.
	 *
	 * This is the one place the override capability (`manage_options`) is
	 * enforced server-side, and it is deliberately the real gate rather than the
	 * meta field's auth_callback. The gate reads the token out of the request
	 * payload, which any client can put anything into, and core's REST controller
	 * writes the post row before it updates meta — so a permission check on the
	 * meta field alone would let an unauthorized token sail past the gate,
	 * publish the post, and only then collect a 403 on a write that no longer
	 * mattered. The meta field therefore only requires edit access (so ordinary
	 * author saves are not refused), and entitlement is decided here: a token
	 * from anyone without manage_options resolves to null and is ignored.
	 *
	 * @param array $meta    Meta supplied with the request.
	 * @param int   $post_id Post ID.
	 * @return array|null
	 */
	private static function resolve_override( array $meta, $post_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		if ( array_key_exists( RENDAR_PC_META_OVERRIDE, $meta ) ) {
			$raw = $meta[ RENDAR_PC_META_OVERRIDE ];
		} elseif ( $post_id ) {
			$raw = get_post_meta( $post_id, RENDAR_PC_META_OVERRIDE, true );
		} else {
			$raw = null;
		}

		return rendar_pc_normalize_override( $raw );
	}

	/**
	 * Does the content carry any real block?
	 *
	 * A classic post opened in the block editor is represented as core/freeform
	 * and may be saved with no block delimiters at all. Both shapes count as
	 * classic here.
	 *
	 * Empty content is not classic. There is nothing legacy about an article
	 * nobody has written yet, and treating it as classic made the panel announce
	 * "skipped: this article's content is classic" on every new post until its
	 * author typed a block.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function content_has_real_blocks( $content ) {
		if ( '' === trim( (string) $content ) ) {
			return true;
		}

		if ( ! has_blocks( $content ) ) {
			return false;
		}

		foreach ( parse_blocks( $content ) as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( null !== $name && 'core/freeform' !== $name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this evaluation for a status that publishes the post?
	 *
	 * `future` counts: scheduling is a commitment to publish, and it is the last
	 * moment an interactive gate can say no — the cron publish that follows
	 * writes the status with a direct query and fires no insert filter.
	 *
	 * @return bool
	 */
	public function is_publishing_status() {
		return in_array( $this->target_status, array( 'publish', 'future', 'private' ), true );
	}

	/**
	 * Should content-image checks be skipped for this post?
	 *
	 * @return bool
	 */
	public function skips_content_checks() {
		return rendar_pc_get_setting( 'skip_classic_content', true ) && ! $this->has_real_blocks;
	}
}
