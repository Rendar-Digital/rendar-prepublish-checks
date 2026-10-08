<?php
/**
 * Scheduled publishing.
 *
 * A scheduled post is gated twice, in two different ways, because the second
 * moment cannot be gated by an insert filter at all.
 *
 * Scheduling is a REST write with a `future` target status, so the real gate
 * catches it and can refuse. The publish that follows is performed by
 * wp_publish_post(), which writes the status with a direct $wpdb->update() and
 * fires no insert filter.
 *
 * So the re-check runs on the cron event itself: `publish_future_post` at
 * priority 5, before core's check_and_publish_future_post() at 10. A failing
 * post is moved to pending while it is still `future`; core then finds a post
 * that is no longer `future` and returns without publishing it. No
 * `future -> publish` transition fires, so no publish listener — Autoshare,
 * FB Auto Publish, Jetpack Sync/Publicize, anything else — is ever handed the
 * post.
 *
 * Running the re-check inside the `future -> publish` transition and
 * reverting from there is not enough. It takes the row back down, but
 * WordPress goes on dispatching the outer transition to every remaining
 * listener with a WP_Post that still says `publish`; Autoshare's
 * publish_tweet() does not re-check the status, and Jetpack emits
 * `jetpack_published_post`. The transition handler
 * is kept only as the fallback for a `future -> publish` that does not come
 * through the cron event (a plugin or WP-CLI calling wp_publish_post()), where
 * there is no earlier moment to act. A post the early re-check already judged
 * in this request is not judged again there, so one failure is recorded and
 * emailed once.
 *
 * **A hold is only claimed once it is stored (2c).** Moving the post to pending
 * goes through wp_update_post(), and then the stored status is read back from
 * the database. wp_update_post() can leave the row where it was without saying
 * so — a `wp_insert_post_data` filter that keeps the status, the
 * `wp_insert_post_empty_content` refusal (which applies to updates too), a
 * database error. If it did not land, the status is written directly and read
 * back again. If even that did not land, the cron path fails closed: core's
 * publisher is detached for this one dispatch, so the post is not published by
 * this event, and the failure is recorded and emailed as NOT held back. Nothing
 * is recorded, emailed or marked "handled" as held until `pending` is in the row.
 *
 * **And only once it is still there after the listeners have run (2d).** Every
 * hold hands the post to status listeners — wp_update_post() dispatches the
 * transition itself, and the direct write dispatches the one it skipped. A
 * `transition_post_status`, `future_to_pending` or `pending_post` listener can
 * re-save the post as `future` (or `publish`) from inside that dispatch. So the
 * row is read again after every dispatch, and a post that has been reopened is
 * reported not held: the cron path then fails closed exactly as it does for a
 * write that never landed.
 *
 * **The record and the email are written after the dispatch, not by the hold
 * (2e).** The hold's result decides what must happen inside the dispatch
 * (reverted / pre-checked registries, detaching core's publisher). The issue
 * itself is queued and finalized once the dispatch is over, from an uncached
 * read (inc/issues.php, rendar_pc_finalize_issue()), so anything that puts
 * the post back after the hold returned is what gets reported.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Posts this request has held back (moved to pending, verified in the row).
 *
 * Read by the transition fallback, so a post already held is not judged again
 * when the outer `future -> publish` transition reaches it, and by the
 * first-published bookkeeping (inc/meta.php), so a held post is never recorded
 * as having gone live. Only a hold that is stored lands here; a failed attempt
 * does not, so nothing downstream mistakes "tried" for "done".
 *
 * @param int|null $post_id Post ID to record, or null to just read.
 * @return int[]
 */
function rendar_pc_reverted_registry( $post_id = null ) {
	static $reverted = array();

	if ( null !== $post_id ) {
		$reverted[ (int) $post_id ] = true;
	}

	return array_keys( $reverted );
}

/**
 * Has this post been held back by the scheduled handler in this request?
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function rendar_pc_was_reverted( $post_id ) {
	return in_array( (int) $post_id, rendar_pc_reverted_registry(), true );
}

/**
 * Posts whose scheduled-publish failure was queued in this request, held or not.
 *
 * Read by rendar_pc_clear_issue_on_publish() (inc/issues.php): when the
 * transition fallback could not take a post back down, the post IS published,
 * and the rest of that same transition must not clear its issue — neither the
 * previous record nor, if anything finalizes early, the new one.
 *
 * @param int|null $post_id Post ID to record, or null to just read.
 * @return int[]
 */
function rendar_pc_failure_recorded_registry( $post_id = null ) {
	static $recorded = array();

	if ( null !== $post_id ) {
		$recorded[ (int) $post_id ] = true;
	}

	return array_keys( $recorded );
}

/**
 * Posts the early re-check has settled in this request.
 *
 * Read by the transition fallback: a post that passed the early re-check and
 * is now being published by core must not be judged a second time a moment
 * later (it could only disagree because wp_publish_post() has since applied a
 * default category), and a post it held back already has its issue queued.
 *
 * A post that failed and could NOT be held is deliberately not recorded here.
 * If anything publishes it anyway, the fallback is the last chance to act, and
 * a registry saying "handled" must not be what switches it off.
 *
 * @param int|null $post_id Post ID to record, or null to just read.
 * @return int[]
 */
function rendar_pc_prechecked_registry( $post_id = null ) {
	static $prechecked = array();

	if ( null !== $post_id ) {
		$prechecked[ (int) $post_id ] = true;
	}

	return array_keys( $prechecked );
}

/**
 * Judge a scheduled post as it is stored, the way it is about to go live.
 *
 * Clears a stale failure record when it passes.
 *
 * @param WP_Post $post Post (read from the database).
 * @return array|null { report, unresolved } on failure, { incomplete: true } when unavailable, null on complete pass.
 */
function rendar_pc_scheduled_verdict( WP_Post $post ) {
	$context = Rendar_PC_Context::build(
		$post->ID,
		array(
			'post_type'    => $post->post_type,
			'post_status'  => 'publish',
			'post_content' => $post->post_content,
		)
	);

	$report = rendar_pc_evaluate( $context );

	// An override consumed by THIS scheduling attempt still stands: the
	// publisher made an explicit, reasoned decision about these exact checks
	// for this date, and cron is not the place to overrule it. Nothing older
	// counts — see rendar_pc_schedule_override_checks(). post_date_gmt is
	// unchanged by wp_publish_post(), so the fallback reads the same attempt.
	$unresolved = array_values( array_diff( $report['blocking_ids'] ?? $report['failing_errors'], rendar_pc_schedule_override_checks( $post->ID, $post->post_date_gmt ) ) );

	if ( ! $unresolved ) {
		// Warning-level unavailability is advisory, but cannot resolve an
		// existing issue on an incomplete evaluation.
		if ( ! rendar_pc_report_is_complete( $report ) ) {
			return array( 'incomplete' => true );
		}
		delete_post_meta( $post->ID, RENDAR_PC_META_SCHEDULE_FAILURE );
		return null;
	}

	return array(
		'report'     => $report,
		'unresolved' => $unresolved,
	);
}

/**
 * Re-check a scheduled post on its cron event, before core publishes it.
 *
 * Mirrors check_and_publish_future_post()'s own guards: only a post that is
 * still `future` and whose time has actually arrived. An event that fires
 * early is left alone, because core will reschedule it rather than publish.
 *
 * @param int $post_id Post ID from the cron event.
 * @return void
 */
function rendar_pc_precheck_future_post( $post_id ) {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post || 'future' !== $post->post_status ) {
		return;
	}

	if ( ! in_array( $post->post_type, rendar_pc_get_post_types(), true ) ) {
		return;
	}

	if ( strtotime( $post->post_date_gmt . ' GMT' ) > time() ) {
		return;
	}

	$verdict = rendar_pc_scheduled_verdict( $post );

	if ( ! empty( $verdict['incomplete'] ) ) {
		// Core still publishes at 10; its future->publish transition must not
		// resolve an issue whose checks could not be completed. Scoped to this
		// post's cron dispatch, not a permanent request-wide exemption.
		$state = &rendar_pc_issue_state();
		$state['incomplete'][ $post->ID ] = true;
		rendar_pc_prechecked_registry( $post->ID );
		return;
	}

	if ( ! $verdict ) {
		// Passed: core publishes it at priority 10, and the fallback must not
		// re-judge the same post a moment later.
		rendar_pc_prechecked_registry( $post->ID );
		return;
	}

	$hold = rendar_pc_hold_post( $post, 'future' );

	if ( $hold['held'] ) {
		rendar_pc_prechecked_registry( $post->ID );
	} else {
		// Fail closed. Either the hold never landed, or a status listener
		// reopened it from inside the hold's own transition (2d). The row
		// most likely says `future`, and core's publisher at priority 10
		// would publish it.
		$hold['publish_blocked'] = rendar_pc_block_core_publish_once();
	}

	rendar_pc_report_scheduled_failure( $post, $verdict, $hold, 'cron-event' );
}
add_action( 'publish_future_post', 'rendar_pc_precheck_future_post', 5, 1 );

/**
 * Fallback: re-check a `future -> publish` that did not come through the cron event.
 *
 * Runs at priority 5 so it precedes the first-published bookkeeping — a post
 * that is about to be reverted must not be recorded as having been published,
 * or every later edit would be treated as an edit to a live article.
 *
 * **What this path does and does not guarantee.** wp_publish_post() cannot be
 * gated beforehand: it writes `publish` with a direct $wpdb->update() and fires
 * no filter first. By the time this runs the row already says `publish`, and
 * after it returns core goes on dispatching the same transition — then
 * `future_to_publish`, `publish_post`, `save_post`, `wp_after_insert_post` — to
 * every other listener with a WP_Post that still says `publish`. So this path
 * does NOT prevent: publish listeners (Autoshare, FB Auto Publish, Jetpack
 * Publicize/Sync) being handed the post, or a concurrent request seeing it
 * public between core's write and the take-down, and it does NOT prevent a
 * listener later in that outer dispatch from re-publishing the post after the
 * take-down.
 *
 * What it does guarantee is scoped to the plugin's own take-down and to what
 * is reported. The take-down is verified through its own dispatches: `pending`
 * read back after wp_update_post() and after the direct write's transition,
 * written directly if wp_update_post() would not (rendar_pc_hold_post()).
 * The record and the email are written after the whole outer dispatch, at
 * wp_publish_post()'s own `wp_after_insert_post` (2e), from an uncached read:
 * "published and taken back down" only if the row then says `pending`;
 * otherwise not held, LIVE (or whatever status the row holds), check it now.
 *
 * It is reached only when something other than the scheduled cron event
 * publishes a scheduled post.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_recheck_scheduled( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'future' !== $old_status ) {
		return;
	}

	if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, rendar_pc_get_post_types(), true ) ) {
		return;
	}

	if ( rendar_pc_was_reverted( $post->ID ) ) {
		return;
	}

	// The cron event's own re-check has already settled this post in this
	// request: it passed, or it failed and is verifiably held. A post that
	// failed and could not be held is not in this registry.
	if ( in_array( (int) $post->ID, rendar_pc_prechecked_registry(), true ) ) {
		return;
	}

	// A person clicking Publish on a scheduled post came through the REST gate,
	// which has already adjudicated this exact save. Re-deciding it here could
	// only disagree with a verdict the publisher has already seen.
	if ( is_array( $GLOBALS['rendar_pc_rest_verdict'] ) ) {
		return;
	}

	$verdict = rendar_pc_scheduled_verdict( $post );

	if ( ! $verdict || ! empty( $verdict['incomplete'] ) ) {
		return;
	}

	$hold = rendar_pc_hold_post( $post, 'publish' );

	rendar_pc_report_scheduled_failure( $post, $verdict, $hold, 'publish-transition' );
}
add_action( 'transition_post_status', 'rendar_pc_recheck_scheduled', 5, 3 );

/**
 * Identity of one scheduling attempt's override: post, scheduled time, checks.
 *
 * @param int      $post_id   Post ID.
 * @param string   $scheduled Scheduled post_date_gmt.
 * @param string[] $checks    Check IDs the override covered.
 * @return string
 */
function rendar_pc_schedule_override_fingerprint( $post_id, $scheduled, array $checks ) {
	$checks = array_values( array_unique( array_map( 'strval', $checks ) ) );
	sort( $checks );

	return md5( implode( '|', array( (int) $post_id, (string) $scheduled, implode( ',', $checks ) ) ) );
}

/**
 * Bind an override to the scheduling attempt that consumed it.
 *
 * A simpler design had every later check subtract every check named anywhere in the
 * cumulative `_rendar_pc_override_log`, so an override given once — for a
 * publish months ago, or for a different date — silently authorized the same
 * failure on every later schedule. That contradicts the one-shot contract.
 * The log is audit-only now; what the stored-state check and the
 * cron re-check honour is this single row, written by the REST save that
 * consumed the token while leaving the post `future`, and describing exactly
 * that attempt: its scheduled time and the checks the override covered.
 *
 * @param WP_Post  $post    The post as saved (status `future`).
 * @param string[] $checks  Check IDs the override covered on that save.
 * @param int      $user_id Who gave the override.
 * @return void
 */
function rendar_pc_bind_schedule_override( WP_Post $post, array $checks, $user_id ) {
	$checks = array_values( array_unique( array_map( 'strval', $checks ) ) );
	sort( $checks );

	update_post_meta(
		$post->ID,
		RENDAR_PC_META_SCHEDULE_OVERRIDE,
		array(
			'scheduled'   => (string) $post->post_date_gmt,
			'checks'      => $checks,
			'fingerprint' => rendar_pc_schedule_override_fingerprint( $post->ID, $post->post_date_gmt, $checks ),
			'time'        => current_time( 'mysql', true ),
			'user_id'     => (int) $user_id,
		)
	);
}

/**
 * The checks the CURRENT scheduling attempt's override covers.
 *
 * Honoured only for the attempt it was given for: the binding must name this
 * exact scheduled time, and its fingerprint must match its own contents. A
 * rescheduled post, a stale audit-log entry, or a row that has been edited by
 * hand authorizes nothing. Only the named checks are subtracted, so a check
 * that starts failing later — one the override never covered — still holds the
 * post back. A post that leaves `future` loses its binding altogether
 * (rendar_pc_expire_schedule_override()), so coming back to the same time
 * later needs a fresh override too.
 *
 * @param int    $post_id   Post ID.
 * @param string $scheduled The post's current post_date_gmt.
 * @return string[]
 */
function rendar_pc_schedule_override_checks( $post_id, $scheduled ) {
	$binding = get_post_meta( $post_id, RENDAR_PC_META_SCHEDULE_OVERRIDE, true );

	if ( ! is_array( $binding ) || empty( $binding['scheduled'] ) || empty( $binding['checks'] ) || ! is_array( $binding['checks'] ) || empty( $binding['fingerprint'] ) ) {
		return array();
	}

	if ( (string) $binding['scheduled'] !== (string) $scheduled ) {
		return array();
	}

	if ( ! hash_equals( rendar_pc_schedule_override_fingerprint( $post_id, $binding['scheduled'], $binding['checks'] ), (string) $binding['fingerprint'] ) ) {
		return array();
	}

	return array_values( array_map( 'strval', $binding['checks'] ) );
}

/**
 * A scheduling attempt ends when the post leaves `future`; so does its override.
 *
 * Published, held back, unscheduled by hand — whichever way, a later schedule
 * is a new attempt and needs its own override. Late, so every other listener
 * on the transition (the fallback re-check at 5 included) still reads it.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_expire_schedule_override( $new_status, $old_status, $post ) {
	if ( 'future' !== $old_status || 'future' === $new_status || ! $post instanceof WP_Post ) {
		return;
	}

	delete_post_meta( $post->ID, RENDAR_PC_META_SCHEDULE_OVERRIDE );
}
add_action( 'transition_post_status', 'rendar_pc_expire_schedule_override', 50, 3 );

/**
 * Move a post to pending, and report only what is actually stored.
 *
 * 1. wp_update_post(), so every plugin watching the status sees the change.
 * 2. Read the row back. wp_update_post() has already dispatched the status
 *    transition and every save hook by the time it returns, so this read is
 *    taken after every listener has had the post. `pending` there is the only
 *    thing that counts as held.
 * 3. If it is not, write the status directly — only while the row still says
 *    `$from_status`, so a concurrent change is never overwritten — then read it
 *    back, and dispatch the status transition the direct write skipped, as
 *    wp_publish_post() does after its own direct write.
 * 4. Read the row AGAIN after that dispatch (2d). A listener on
 *    the transition it fires can re-save the post as `future` or `publish`;
 *    a hold that has been reopened is not a hold, and returning `held: true`
 *    there left core's publisher attached to a post the record and the email
 *    said was held back.
 *
 * Every `held: true` below is returned from a read taken after the last
 * dispatch this function caused. Nothing is retried: a listener that reopens
 * the post once will reopen it again, and the caller's fail-closed path is the
 * right answer to that, not a loop.
 *
 * This result only drives what the caller must do inside the dispatch
 * (detach core's publisher, mark the post reverted). It is not what gets
 * recorded or emailed: that is read again once the outer dispatch is over
 * (rendar_pc_finalize_issue(), 2e).
 *
 * The direct write deliberately goes around whatever stopped step 1. A filter
 * that keeps a failing post `future` is asking for exactly what this plugin
 * exists to prevent; if that is ever intended, an override is the way.
 *
 * @param WP_Post $post        Post.
 * @param string  $from_status The status the row is expected to hold now (`future`, or `publish` on the fallback).
 * @return array { held: bool, method: 'update'|'direct'|'', stored_status: string, error: string }
 */
function rendar_pc_hold_post( WP_Post $post, $from_status ) {
	global $wpdb;

	$post_id = (int) $post->ID;
	$result  = wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'pending',
		),
		true
	);
	$error   = is_wp_error( $result ) ? $result->get_error_code() : ( $result ? '' : 'update-returned-0' );
	$stored  = rendar_pc_stored_status( $post_id );

	if ( 'pending' === $stored ) {
		return array(
			'held'          => true,
			'method'        => 'update',
			'stored_status' => $stored,
			'error'         => '',
		);
	}

	if ( '' === $error ) {
		$error = 'not-pending-after-update:' . $stored;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The fallback write IS the direct query; the cache is cleaned by the read-back below.
	$wpdb->update(
		$wpdb->posts,
		array( 'post_status' => 'pending' ),
		array(
			'ID'          => $post_id,
			'post_status' => $from_status,
		)
	);

	$stored = rendar_pc_stored_status( $post_id );

	if ( 'pending' !== $stored ) {
		return array(
			'held'          => false,
			'method'        => '',
			'stored_status' => $stored,
			'error'         => $error,
		);
	}

	$held_post = get_post( $post_id );

	if ( $held_post instanceof WP_Post ) {
		wp_transition_post_status( 'pending', $from_status, $held_post );
	}

	// The listeners have had the post; only now is `pending` a hold.
	$stored = rendar_pc_stored_status( $post_id );

	if ( 'pending' !== $stored ) {
		return array(
			'held'          => false,
			'method'        => '',
			'stored_status' => $stored,
			'error'         => 'reopened-after-transition:' . $stored,
		);
	}

	return array(
		'held'          => true,
		'method'        => 'direct',
		'stored_status' => $stored,
		'error'         => $error,
	);
}

/**
 * Detach core's scheduled publisher for the current `publish_future_post` dispatch only.
 *
 * The last line on the cron path, used only when a failing post could not be
 * moved to pending: core's check_and_publish_future_post() (priority 10) would
 * otherwise publish it a moment later. It is re-attached at the very end of
 * this same dispatch, so the next scheduled post in the same cron run is
 * published normally. WP_Hook re-sorts a running iteration when a callback is
 * removed or added mid-dispatch: a priority removed ahead of the current one is
 * skipped, and a callback re-added behind it does not run again.
 *
 * @return bool True when core's publisher is no longer attached for the rest of this dispatch.
 */
function rendar_pc_block_core_publish_once() {
	$priority = has_action( 'publish_future_post', 'check_and_publish_future_post' );

	if ( false === $priority ) {
		return true;
	}

	remove_action( 'publish_future_post', 'check_and_publish_future_post', $priority );
	rendar_pc_core_publisher_priority( $priority );

	if ( false === has_action( 'publish_future_post', 'rendar_pc_restore_core_publish' ) ) {
		add_action( 'publish_future_post', 'rendar_pc_restore_core_publish', PHP_INT_MAX, 0 );
	}

	return false === has_action( 'publish_future_post', 'check_and_publish_future_post' );
}

/**
 * Remember the priority core's publisher was detached from.
 *
 * @param int|null $priority Priority to remember, or null to read.
 * @return int|null
 */
function rendar_pc_core_publisher_priority( $priority = null ) {
	static $stored = null;

	if ( null !== $priority ) {
		$stored = (int) $priority;
	}

	return $stored;
}

/**
 * Re-attach core's publisher at the end of the dispatch that detached it.
 *
 * @return void
 */
function rendar_pc_restore_core_publish() {
	remove_action( 'publish_future_post', 'rendar_pc_restore_core_publish', PHP_INT_MAX );

	$priority = rendar_pc_core_publisher_priority();

	if ( null !== $priority && false === has_action( 'publish_future_post', 'check_and_publish_future_post' ) ) {
		add_action( 'publish_future_post', 'check_and_publish_future_post', $priority, 1 );
	}
}

/**
 * Queue the issue for a scheduled post that failed its re-check.
 *
 * The hold's own result still decides everything that has to happen inside
 * this dispatch — whether the post is marked reverted (first-published
 * bookkeeping skips it) and, on the cron path, whether core's publisher was
 * detached — because those cannot wait. The record and the email do not
 * decide anything, so they wait (2e): rendar_pc_finalize_issue() writes
 * them once the dispatch is over — the end of `publish_future_post`, or the
 * `wp_after_insert_post` of the wp_publish_post() the fallback ran inside —
 * from an uncached read. A publish listener, or a `publish_future_post`
 * callback between 5 and 10, that puts the post back after the hold returned
 * is therefore reported, not papered over.
 *
 * The cron-event intent is owned by its dispatch: a same-post save
 * nested anywhere inside the dispatch reaches `wp_after_insert_post` long
 * before the dispatch ends, and must not finalize it there.
 *
 * @param WP_Post $post    Post.
 * @param array   $verdict { report, unresolved }.
 * @param array   $hold    Result of rendar_pc_hold_post(), plus `publish_blocked` on the cron path.
 * @param string  $path    `cron-event` or `publish-transition`.
 * @return void
 */
function rendar_pc_report_scheduled_failure( WP_Post $post, array $verdict, array $hold, $path ) {
	if ( $hold['held'] ) {
		rendar_pc_reverted_registry( $post->ID );
	}

	rendar_pc_failure_recorded_registry( $post->ID );

	rendar_pc_queue_issue(
		$post->ID,
		array(
			'source'          => RENDAR_PC_ISSUE_SOURCE_CRON,
			'path'            => (string) $path,
			'post_type'       => $post->post_type,
			'intended_status' => 'future',
			'scheduled'       => $post->post_date_gmt,
			'user_id'         => 0,
			'failing'         => $verdict['unresolved'],
			'report'          => $verdict['report'],
			'publish_blocked' => ! empty( $hold['publish_blocked'] ),
			'dispatch_owned'  => 'cron-event' === $path,
		),
		'publish-transition' === $path
	);
}

/**
 * Posts currently sitting on a failed scheduled publish.
 *
 * @param int $limit Maximum number to return.
 * @return WP_Post[]
 */
function rendar_pc_get_failed_scheduled_posts( $limit = 50 ) {
	$query = new WP_Query(
		array(
			'post_type'        => rendar_pc_get_post_types(),
			'post_status'      => array( 'pending', 'draft', 'future' ),
			'posts_per_page'   => (int) $limit,
			'meta_key'         => RENDAR_PC_META_SCHEDULE_FAILURE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded admin-only query on an indexed meta key.
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);

	return $query->posts;
}
