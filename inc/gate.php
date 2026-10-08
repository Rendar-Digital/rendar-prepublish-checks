<?php
/**
 * The publish gate.
 *
 * Two entry points, deliberately unequal in standing:
 *
 * - `rest_pre_insert_{post_type}` refuses publishing writes through the posts
 *   controller. Core's autosaves controller ignores WP_Error from that filter;
 *   `rest_request_before_callbacks` separately refuses unauthorized autosave
 *   override attempts before its callback can update a draft or revision.
 * - `wp_insert_post_data` is a backstop for Quick Edit and other admin-side
 *   paths. It cannot refuse a save, so it demotes the status back to pending
 *   and leaves a notice.
 * - After a non-REST write has fully landed (`wp_after_insert_post`), a post
 *   left `future` is judged once more exactly as it is stored — terms, featured
 *   image, ACF values and all — and moved to pending if it fails. This is what
 *   adjudicates the block editor's meta-box follow-up, which the insert filter
 *   cannot see the result of.
 *
 * Neither one touches a draft or a pending save. On many editorial sites
 * authors work to pending and an editor publishes; making an author fight the checks to save
 * their own work in progress would be the fastest way to get the plugin turned
 * off.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the REST gate has already adjudicated the current request.
 *
 * Read by the backstop, so one save is never handled twice, and by the
 * scheduled re-check, so a manual publish of a scheduled post is not
 * re-evaluated after the gate already allowed it.
 *
 * @var array|null
 */
$GLOBALS['rendar_pc_rest_verdict'] = null;

/**
 * Register the per-post-type hooks.
 *
 * @return void
 */
function rendar_pc_register_gate() {
	foreach ( rendar_pc_get_post_types() as $post_type ) {
		add_filter( "rest_pre_insert_{$post_type}", 'rendar_pc_rest_gate', 10, 2 );
		add_action( "rest_after_insert_{$post_type}", 'rendar_pc_after_rest_insert', 10, 3 );
	}
}
// Match meta registration: late init CPTs must have both hooks before REST routes run.
add_action( 'init', 'rendar_pc_register_gate', PHP_INT_MAX );
add_filter( 'rest_request_before_callbacks', 'rendar_pc_autosave_override_gate', 10, 3 );

/**
 * A nonempty override field is an attempt, including malformed input. An empty
 * editor field is not. Reject malformed nonempty containers rather than relying
 * on later REST schema validation to protect a post row.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return bool
 */
function rendar_pc_has_override_attempt( $request ) {
	foreach ( array( 'meta', 'acf' ) as $field ) {
		$values = $request[ $field ];
		if ( is_array( $values ) ) {
			if ( array_key_exists( RENDAR_PC_META_OVERRIDE, $values ) && ! empty( $values[ RENDAR_PC_META_OVERRIDE ] ) ) {
				return true;
			}
		} elseif ( ! empty( $values ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Core 6.6/6.8 autosaves create_item() ignores the WP_Error returned by its
 * parent prepare_item_for_database(). Guard its matched callback before it can
 * write the original draft row (or a revision). Leave ordinary autosaves alone.
 *
 * @param mixed           $response Prior pre-callback result.
 * @param array           $handler  Matched REST handler.
 * @param WP_REST_Request $request  Matched request.
 * @return mixed
 */
function rendar_pc_autosave_override_gate( $response, $handler, $request ) {
	if ( ! $request instanceof WP_REST_Request || ! isset( $handler['callback'] ) || ! is_array( $handler['callback'] )
		|| ! isset( $handler['callback'][0], $handler['callback'][1] )
		|| ! $handler['callback'][0] instanceof WP_REST_Autosaves_Controller || 'create_item' !== $handler['callback'][1] ) {
		return $response;
	}

	$post_id = (int) $request['id'];
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, rendar_pc_get_post_types(), true )
		|| ! rendar_pc_has_override_attempt( $request ) ) {
		return $response;
	}

	if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'rendar_prepublish_checks_override_forbidden', __( 'Only an administrator who can edit this post can override pre-publish checks.', 'rendar-prepublish-checks' ), array( 'status' => 403 ) );
	}
	return $response;
}


/**
 * Refuse a REST write that would publish a post failing an error-severity check.
 *
 * @param stdClass        $prepared_post Post data prepared for the database.
 * @param WP_REST_Request $request       The request.
 * @return stdClass|WP_Error
 */
function rendar_pc_rest_gate( $prepared_post, $request ) {
	// The post row is written before REST meta authorization runs. Refuse a
	// non-admin token HERE, before the row can become public; the meta field's
	// auth_callback is not a publish permission check. An empty value is sent
	// by the block editor on ordinary saves and is not an override attempt.
	$post_id = isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0;
	if ( $request instanceof WP_REST_Request && rendar_pc_has_override_attempt( $request )
		&& ( ! current_user_can( 'manage_options' ) || ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) ) ) {
		return new WP_Error( 'rendar_prepublish_checks_override_forbidden', __( 'Only an administrator who can edit this post can override pre-publish checks.', 'rendar-prepublish-checks' ), array( 'status' => 403 ) );
	}

	$context = Rendar_PC_Context::build( $post_id, rendar_pc_overrides_from_rest( $prepared_post, $request ) );
	$report  = rendar_pc_evaluate( $context );

	$GLOBALS['rendar_pc_rest_verdict'] = $report;

	if ( ! $report['blocking'] ) {
		return $prepared_post;
	}

	$messages = rendar_pc_failing_messages( $report );

	return new WP_Error(
		'rendar_prepublish_checks_blocked',
		sprintf(
			/* translators: %s: list of failing checks. */
			__( 'This article is not ready to publish. %s', 'rendar-prepublish-checks' ),
			implode( ' ', $messages )
		),
		array(
			'status'         => 400,
			'failing_checks' => $report['failing_errors'],
			'blocking_checks' => $report['blocking_ids'],
			'checks'         => $report['checks'],
			'can_override'   => current_user_can( 'manage_options' ),
		)
	);
}

/**
 * Translate a REST write into context overrides.
 *
 * Only keys the request actually carries are set. A partial update — the
 * editor changing nothing but the status — must fall back to the saved post
 * for everything else, and an override key present with a null value would
 * wrongly read as "cleared".
 *
 * Terms are read from the request rather than the prepared post because the
 * posts controller does not put them there: `handle_terms()` runs after the
 * row is written, which is much too late for a gate.
 *
 * `acf` merges into the same `meta` override bucket `rendar_pc_read_field_value()`
 * already reads (array_key_exists( $field['name'], $meta )) — no separate
 * resolution path needed. When an ACF field group has `show_in_rest` enabled,
 * ACF's own REST field accepts and persists a value supplied this way, but
 * neither WordPress core nor ACF required-checks it on write — an incomplete
 * `acf` param publishes successfully with the empty sub-field saved as empty.
 * The block editor's classic side metaboxes do not populate `acf` either, so
 * the ordinary editorial workflow is unaffected. What reading it here closes
 * is the risk `show_in_rest` introduces: without it, a client that sends a
 * complete `acf` param on a first-time publish — with nothing separately saved
 * first — would be false-blocked by this plugin's own required-custom-fields
 * check, which would otherwise only ever see the (still-empty) saved post
 * state.
 *
 * @param stdClass        $prepared_post Prepared post data.
 * @param WP_REST_Request $request       The request.
 * @return array
 */
function rendar_pc_overrides_from_rest( $prepared_post, $request ) {
	$overrides = array();

	if ( isset( $prepared_post->post_type ) ) {
		$overrides['post_type'] = $prepared_post->post_type;
	}

	if ( isset( $prepared_post->post_status ) ) {
		$overrides['post_status'] = $prepared_post->post_status;
	}

	if ( isset( $prepared_post->post_content ) ) {
		$overrides['post_content'] = $prepared_post->post_content;
	}

	if ( $request instanceof WP_REST_Request ) {
		if ( null !== $request['featured_media'] ) {
			$overrides['featured_media'] = (int) $request['featured_media'];
		}

		foreach ( array( 'categories', 'tags' ) as $param ) {
			if ( is_array( $request[ $param ] ) ) {
				$overrides[ $param ] = $request[ $param ];
			}
		}

		$meta = array();

		if ( is_array( $request['meta'] ) ) {
			$meta = $request['meta'];
		}

		if ( is_array( $request['acf'] ) ) {
			// acf wins on the (practically impossible) key collision — it is
			// the more specific, more intentional of the two sources for a
			// given request.
			$meta = array_merge( $meta, $request['acf'] );
		}

		if ( $meta ) {
			$overrides['meta'] = $meta;
		}
	}

	return $overrides;
}

/**
 * After a successful REST write: consume the override, clear a stale failure.
 *
 * The override token is one-shot. It is moved into the audit log and deleted
 * here, on the save it accompanied, so it cannot sit in meta and silently
 * authorize a later publish that fails something else.
 *
 * @param WP_Post         $post    The saved post.
 * @param WP_REST_Request $request The request.
 * @param bool            $creating Whether this was a create.
 * @return void
 */
function rendar_pc_after_rest_insert( $post, $request, $creating ) {
	unset( $request, $creating );

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$covered = rendar_pc_consume_override( $post->ID );

	// Bind the override to this scheduling attempt, or drop the previous
	// attempt's binding. A REST save that leaves the post `future` IS the
	// current attempt: if it consumed an override, that override (its checks,
	// this date) is what the stored-state check and the cron re-check honour;
	// if it passed the gate without one, no earlier override is honoured any
	// more. See rendar_pc_schedule_override_checks().
	if ( 'future' === $post->post_status && $covered ) {
		rendar_pc_bind_schedule_override( $post, $covered, get_current_user_id() );
	} else {
		delete_post_meta( $post->ID, RENDAR_PC_META_SCHEDULE_OVERRIDE );
	}

	// The gate has just passed this post for a publishing status, so any
	// earlier "did not go live" record is resolved — including a reschedule
	// (future), which the cron re-check will judge again at its own time.
	if ( in_array( $post->post_status, array( 'publish', 'private', 'future' ), true ) && rendar_pc_report_is_complete( $GLOBALS['rendar_pc_rest_verdict'] ?? null ) ) {
		delete_post_meta( $post->ID, RENDAR_PC_META_SCHEDULE_FAILURE );
	}
}

/**
 * Move any active override token into the audit log and clear it.
 *
 * The log is an audit trail and nothing else: no check reads it to decide
 * anything (2c). What a scheduled post's later checks honour is the binding
 * rendar_pc_after_rest_insert() writes from the return value.
 *
 * @param int $post_id Post ID.
 * @return string[] Check IDs the token actually covered on this save; empty when it covered none.
 */
function rendar_pc_consume_override( $post_id ) {
	$raw = get_post_meta( $post_id, RENDAR_PC_META_OVERRIDE, true );

	if ( ! $raw ) {
		return array();
	}

	$override = rendar_pc_normalize_override( $raw );

	delete_post_meta( $post_id, RENDAR_PC_META_OVERRIDE );

	if ( ! $override ) {
		return array();
	}

	$verdict = isset( $GLOBALS['rendar_pc_rest_verdict'] ) ? $GLOBALS['rendar_pc_rest_verdict'] : null;

	// Only log the token when it actually did something. A token attached to a
	// save that would have passed anyway is noise in an audit trail, and an
	// audit trail nobody trusts is worse than none.
	if ( ! is_array( $verdict ) || empty( $verdict['override_covers'] ) ) {
		return array();
	}

	rendar_pc_append_override_log(
		$post_id,
		array(
			'time'    => current_time( 'mysql', true ),
			'user_id' => get_current_user_id(),
			'reason'  => $override['reason'],
			'checks'  => array_values( $verdict['override_covers'] ),
		)
	);

	return array_values( $verdict['override_covers'] );
}

/**
 * Backstop for admin-side writes that do not go through REST.
 *
 * Quick Edit and bulk edit are the realistic cases. This cannot refuse the
 * save, so it demotes the target status to pending and queues a Publishing
 * Issue. The issue — record, notice and failure email — is written after the
 * save is over, from the status then stored (rendar_pc_finalize_issue()).
 *
 * Deliberately inert for imports, WP-CLI, cron and unauthenticated writes. An
 * importer may publish as it goes; silently demoting an import to pending would be a far worse failure than not checking it. The
 * scheduled-publish path has its own handler.
 *
 * **Both arrays arrive slashed.** Core runs this filter on the slashed data and
 * only calls wp_unslash() after it returns (wp_insert_post(), `$data =
 * wp_unslash( $data )` directly below the apply_filters call), and
 * wp_update_post() re-slashes the row it reads back from the database. Handing
 * slashed content to the evaluator is not cosmetic: every `"` in block-comment
 * JSON becomes `\"`, parse_blocks() can no longer decode any block's
 * attributes, and every image in a gallery block that stores its images in
 * block attributes loses its attachment ID — so
 * the credit check reports "N images are not in the media library" and fails a
 * post the REST gate passed a moment earlier. Only the evaluation reads the
 * unslashed copy; the returned `$data` stays slashed, because core still has
 * to unslash it.
 *
 * @param array $data    Sanitised post data about to be written (slashed).
 * @param array $postarr Raw post array (slashed).
 * @return array
 */
function rendar_pc_insert_backstop( $data, $postarr ) {
	if ( ! rendar_pc_backstop_applies( $data, $postarr ) ) {
		return $data;
	}

	$clean_data    = wp_unslash( $data );
	$clean_postarr = wp_unslash( $postarr );

	$post_id = isset( $clean_postarr['ID'] ) ? (int) $clean_postarr['ID'] : 0;

	// The block editor's meta-box follow-up is judged after it has saved, on
	// the stored post — see rendar_pc_is_meta_box_followup() for why not
	// here. It is deferred, never exempted: the shape of a request only decides
	// WHEN it is adjudicated, so forging that shape buys nothing.
	if ( rendar_pc_is_meta_box_followup( $data['post_status'], $postarr ) ) {
		rendar_pc_defer_stored_check( $post_id );
		return $data;
	}

	$overrides = rendar_pc_overrides_from_postarr( $clean_data, $clean_postarr );

	$context = Rendar_PC_Context::build( $post_id, $overrides );
	$report  = rendar_pc_evaluate( $context );

	if ( ! $report['blocking'] ) {
		// Passing here is judged on the request plus the old row. Anything the
		// save writes later — ACF on save_post, a nested write — is judged on
		// the stored post once the write has landed. Only a schedule is worth
		// that: a publish has already been announced by the time it could run.
		if ( $post_id && 'future' === $data['post_status'] ) {
			rendar_pc_defer_stored_check( $post_id );
		}

		return $data;
	}

	$intended            = $data['post_status'];
	$data['post_status'] = 'pending';

	// Only the intent is queued here. The record, the notice and the email are
	// written once the whole save is over, from what the row then says (2e):
	// a later filter or listener in this same save can still put the post back
	// to `future` or `publish`, and then it must be reported not held. A new
	// post has no ID yet; it is bound at its first transition.
	rendar_pc_queue_issue(
		$post_id,
		array(
			'source'          => RENDAR_PC_ISSUE_SOURCE_BACKSTOP,
			'path'            => 'save',
			'post_type'       => $data['post_type'],
			'intended_status' => $intended,
			'failing'         => rendar_pc_blocking_checks( $report ),
			'report'          => $report,
			'user_id'         => get_current_user_id(),
		)
	);

	return $data;
}
add_filter( 'wp_insert_post_data', 'rendar_pc_insert_backstop', 99, 2 );

/**
 * Should the backstop run for this write?
 *
 * Every non-REST write that targets a publishing status is adjudicated —
 * classic editor, Quick Edit, Bulk Edit, XML-RPC and a logged-in user's
 * wp_update_post(), including one that leaves a scheduled or published post's
 * status where it was. A classic edit of a scheduled post changes what will go
 * live, and the only other check it would meet is the cron-time re-check, which
 * can take the article back down but cannot tell the author in time.
 *
 * The block editor's meta-box follow-up save passes this test too. It is not
 * exempt; rendar_pc_insert_backstop() defers it to the stored-state check
 * (see rendar_pc_is_meta_box_followup()).
 *
 * @param array $data    Sanitised post data.
 * @param array $postarr Raw post array; only `ID` is read.
 * @return bool
 */
function rendar_pc_backstop_applies( array $data, array $postarr = array() ) {
	if ( is_array( $GLOBALS['rendar_pc_rest_verdict'] ) ) {
		return false;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
		return false;
	}

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( empty( $data['post_type'] ) || ! in_array( $data['post_type'], rendar_pc_get_post_types(), true ) ) {
		return false;
	}

	unset( $postarr );

	$status = isset( $data['post_status'] ) ? $data['post_status'] : '';

	return in_array( $status, array( 'publish', 'future', 'private' ), true );
}

/**
 * Is this write the block editor's meta-box follow-up to a REST save?
 *
 * When a block-editor save completes, Gutenberg re-posts every classic meta box
 * (ACF, Yoast, the Facebook plugin) to `post.php?meta-box-loader=1`. That is a
 * second, non-REST edit_post() for the same save, carrying no `post_status`, so
 * core re-applies whatever status the REST write just stored.
 *
 * Judging it here, inside wp_insert_post_data, is judging the wrong thing: the
 * request carries no content (the REST write saved it), and the values it does
 * carry — ACF fields, a featured image, terms — are written after this filter
 * (ACF on save_post). Re-deciding it here demoted every just-scheduled
 * gallery article to pending two seconds after the editor said "Scheduled".
 *
 * Exempting this request instead is no better: the exemption would be bought by the request's shape, and a hand-built follow-up can
 * strip categories, tags, the featured image or ACF values from a scheduled
 * post while its status stays `future`. So nothing is exempted any more. A
 * request that answers true here is deferred to rendar_pc_run_stored_check(),
 * which judges the post as stored once the whole save has landed. Forging this
 * shape therefore gets a stricter check (the stored result, ACF included), not
 * a weaker one; failing to match it gets the synchronous check, as before.
 *
 * The conditions below now only decide timing, but each still keeps a request
 * that is plainly not the follow-up on the synchronous path:
 *
 * 1. **An admin POST to post.php** (`window._wpMetaBoxUrl` is built from
 *    `admin_url( 'post.php' )`).
 * 2. **The `meta-box-loader` flag with a valid `meta-box-loader` nonce.**
 * 3. **`action=editpost` for this very post**, so a nested wp_update_post() of
 *    some other post inside the follow-up is judged on its own.
 * 4. **No editor fields in the POST** (`content`, `post_title`, `excerpt`).
 * 5. **The status is unchanged.** If the scheduled minute passed between the
 *    REST write and the follow-up, core turns `future` into `publish` on this
 *    request; that is a publish, and a publish must be judged before it
 *    happens, not after.
 *
 * @param string $status  Target status computed by core for this write.
 * @param array  $postarr Raw post array (slashed); only `ID` is read.
 * @return bool
 */
function rendar_pc_is_meta_box_followup( $status, array $postarr ) {
	// phpcs:disable WordPress.Security.NonceVerification -- The meta-box nonce is verified below; the update-post nonce was verified by post.php before edit_post().
	if ( ! is_admin() || ! isset( $GLOBALS['pagenow'] ) || 'post.php' !== $GLOBALS['pagenow'] ) {
		return false;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

	if ( 'POST' !== $method ) {
		return false;
	}

	if ( empty( $_GET['meta-box-loader'] ) || empty( $_GET['meta-box-loader-nonce'] ) ) {
		return false;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meta-box-loader-nonce'] ) ), 'meta-box-loader' ) ) {
		return false;
	}

	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

	if ( ! $post_id || ! isset( $_POST['action'] ) || 'editpost' !== sanitize_key( wp_unslash( $_POST['action'] ) ) ) {
		return false;
	}

	if ( ! isset( $_POST['post_ID'] ) || (int) $_POST['post_ID'] !== $post_id ) {
		return false;
	}

	foreach ( array( 'content', 'post_title', 'excerpt' ) as $editor_field ) {
		if ( isset( $_POST[ $editor_field ] ) ) {
			return false;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification

	return get_post_status( $post_id ) === $status;
}

/**
 * Stored-state checks waiting for their write to land.
 *
 * Post ID => number of wp_insert_post() calls for that post still open. The
 * check runs when the outermost one finishes, so a save_post callback that
 * re-saves the same post cannot trigger it half-way through the outer save.
 *
 * @param string $action  defer | enter | leave | take-all | get.
 * @param int    $post_id Post ID.
 * @return mixed `leave`: true when the outermost write just finished;
 *               `take-all`: the remaining IDs (cleared); otherwise the map.
 */
function rendar_pc_deferred_checks( $action = 'get', $post_id = 0 ) {
	static $open = array();

	$post_id = (int) $post_id;

	switch ( $action ) {
		case 'defer':
			if ( $post_id > 0 && ! isset( $open[ $post_id ] ) ) {
				$open[ $post_id ] = 0;
			}
			break;

		case 'enter':
			if ( isset( $open[ $post_id ] ) ) {
				++$open[ $post_id ];
			}
			break;

		case 'leave':
			if ( ! isset( $open[ $post_id ] ) ) {
				return false;
			}

			--$open[ $post_id ];

			if ( $open[ $post_id ] > 0 ) {
				return false;
			}

			unset( $open[ $post_id ] );
			return true;

		case 'take-all':
			$ids  = array_keys( $open );
			$open = array();
			return $ids;
	}

	return $open;
}

/**
 * Judge this post as stored once the current write has landed.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function rendar_pc_defer_stored_check( $post_id ) {
	if ( (int) $post_id <= 0 ) {
		return;
	}

	rendar_pc_deferred_checks( 'defer', $post_id );

	// Safety net for a write that never reaches wp_after_insert_post (a
	// database error returns before it). Registered only when something was
	// deferred, so no ordinary request carries it.
	if ( false === has_action( 'shutdown', 'rendar_pc_run_leftover_stored_checks' ) ) {
		add_action( 'shutdown', 'rendar_pc_run_leftover_stored_checks', 0 );
	}
}

/**
 * Count a write for a post with a deferred check.
 *
 * Last on wp_insert_post_data, so the backstop (99) has already deferred it.
 *
 * @param array $data    Post data.
 * @param array $postarr Raw post array.
 * @return array Unchanged.
 */
function rendar_pc_deferred_enter( $data, $postarr ) {
	if ( ! empty( $postarr['ID'] ) ) {
		rendar_pc_deferred_checks( 'enter', (int) $postarr['ID'] );
	}

	return $data;
}
add_filter( 'wp_insert_post_data', 'rendar_pc_deferred_enter', PHP_INT_MAX, 2 );

/**
 * Run a deferred check once its write — and everything that write fires — is done.
 *
 * The `wp_after_insert_post` action is the first moment the whole save is on disk: it fires
 * after `save_post` (ACF Pro 6.8.10 saves its fields there, priority 10) and
 * after `wp_insert_post` (Yoast), with the terms and featured image that
 * wp_insert_post() itself writes already stored. Late priority, so every other
 * listener has seen the save as it was made before this can change it.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function rendar_pc_deferred_after_insert( $post_id ) {
	if ( rendar_pc_deferred_checks( 'leave', $post_id ) ) {
		rendar_pc_run_stored_check( $post_id );
	}
}
add_action( 'wp_after_insert_post', 'rendar_pc_deferred_after_insert', 999, 1 );

/**
 * Shutdown safety net: run any check whose write never reported back.
 *
 * @return void
 */
function rendar_pc_run_leftover_stored_checks() {
	foreach ( rendar_pc_deferred_checks( 'take-all' ) as $post_id ) {
		rendar_pc_run_stored_check( $post_id );
	}
}

/**
 * Judge a scheduled post exactly as it is stored, and move it to pending if it fails.
 *
 * Only a post still `future` is judged. A post that went to pending on the
 * synchronous path already has its issue queued; a published post is never
 * gated (rule 2) and could not be taken back without unpublishing a live
 * article. A failure is queued, not recorded: see rendar_pc_queue_issue().
 *
 * Nothing is read from the request. The context is built from the database —
 * unslashed by definition, which is why a genuine gallery article passes here
 * when it is judged on its stored block JSON.
 *
 * An override is honoured two ways: a live token (for an administrator), which
 * the evaluator resolves itself; and the override bound to the current
 * scheduling attempt, because the REST save this follow-up belongs to has
 * already consumed its token and bound it to this date and these checks. The
 * audit log is never read here (2c). Same rule as the cron re-check.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function rendar_pc_run_stored_check( $post_id ) {
	clean_post_cache( $post_id );
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'future' !== $post->post_status ) {
		return;
	}

	if ( ! in_array( $post->post_type, rendar_pc_get_post_types(), true ) ) {
		return;
	}

	$report = rendar_pc_evaluate( Rendar_PC_Context::build( $post->ID ) );

	if ( empty( $report['blocking'] ) ) {
		return;
	}

	$unresolved = array_values( array_diff( rendar_pc_blocking_checks( $report ), rendar_pc_schedule_override_checks( $post->ID, $post->post_date_gmt ) ) );

	if ( ! $unresolved ) {
		return;
	}

	// Same verified hold as the cron path (inc/scheduled.php). Whether it held
	// is not decided here: the issue is queued and finalized when this save is
	// over (2e), from the status then stored, so a listener that reopens the
	// post afterwards gets it reported not held. A hold that did not land at
	// all is reported the same way — the post is still `future`, failing, and
	// someone needs to know now rather than at its publish time.
	rendar_pc_hold_post( $post, 'future' );

	rendar_pc_queue_issue(
		$post->ID,
		array(
			'source'          => RENDAR_PC_ISSUE_SOURCE_BACKSTOP,
			'path'            => 'stored-check',
			'post_type'       => $post->post_type,
			'intended_status' => 'future',
			'scheduled'       => $post->post_date_gmt,
			'failing'         => $unresolved,
			'report'          => $report,
			'user_id'         => get_current_user_id(),
		)
	);
}

/**
 * Translate a classic-path write into context overrides.
 *
 * Quick Edit does send its term selection in the post array, so the terms are
 * read from there when present — the saved row would be a lap behind.
 *
 * @param array $data    Sanitised post data.
 * @param array $postarr Raw post array.
 * @return array
 */
function rendar_pc_overrides_from_postarr( array $data, array $postarr ) {
	$overrides = array(
		'post_type'   => isset( $data['post_type'] ) ? $data['post_type'] : 'post',
		'post_status' => isset( $data['post_status'] ) ? $data['post_status'] : 'draft',
	);

	if ( isset( $data['post_content'] ) ) {
		$overrides['post_content'] = $data['post_content'];
	}

	if ( isset( $postarr['post_category'] ) && is_array( $postarr['post_category'] ) ) {
		$overrides['categories'] = $postarr['post_category'];
	}

	if ( isset( $postarr['tax_input']['post_tag'] ) ) {
		$overrides['tags'] = rendar_pc_resolve_tag_input( $postarr['tax_input']['post_tag'] );
	}

	if ( isset( $postarr['_thumbnail_id'] ) ) {
		$overrides['featured_media'] = (int) $postarr['_thumbnail_id'];
	}

	return $overrides;
}

/**
 * Resolve Quick Edit's tag input into term IDs.
 *
 * Quick Edit sends a comma-separated list of tag *names*, not IDs, and names
 * that do not exist yet are perfectly valid — they are created on save. What
 * this check needs to know is only whether the author supplied any, so unknown
 * names resolve to a placeholder rather than being dropped.
 *
 * @param mixed $input Raw tax_input value.
 * @return int[]
 */
function rendar_pc_resolve_tag_input( $input ) {
	if ( is_array( $input ) ) {
		$names = $input;
	} else {
		$names = array_filter( array_map( 'trim', explode( ',', (string) $input ) ) );
	}

	$ids = array();

	foreach ( $names as $name ) {
		if ( is_numeric( $name ) ) {
			$ids[] = (int) $name;
			continue;
		}

		$term = get_term_by( 'name', $name, 'post_tag' );

		// A tag about to be created has no ID yet. It still counts as "the
		// author set a tag", which is the only question being asked.
		$ids[] = ( $term instanceof WP_Term ) ? (int) $term->term_id : -1;
	}

	return array_values( array_filter( $ids ) );
}

/**
 * Transient key for a user's pending admin notice.
 *
 * @param int $user_id User ID.
 * @return string
 */
function rendar_pc_notice_transient_key( $user_id ) {
	return 'rendar_pc_notice_' . (int) $user_id;
}

/**
 * The check IDs that make a report blocking.
 *
 * With a partial override in play only the checks it does not cover are the
 * reason; otherwise every blocking ID is. Same rule rendar_pc_failing_messages() uses.
 *
 * @param array $report Evaluation report.
 * @return string[]
 */
function rendar_pc_blocking_checks( array $report ) {
	if ( ! empty( $report['blocking'] ) && ! empty( $report['override_missing'] ) ) {
		return array_values( $report['override_missing'] );
	}

	return isset( $report['blocking_ids'] ) ? array_values( $report['blocking_ids'] ) : array();
}
