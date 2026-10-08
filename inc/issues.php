<?php
/**
 * Publishing issues: one record, two sources.
 *
 * An article that was meant to go live and did not is recorded in the same
 * post meta row whichever path stopped it:
 *
 * - `cron`     — the scheduled re-check took a failing post back down at its
 *                publish time (inc/scheduled.php);
 * - `backstop` — the wp_insert_post_data backstop demoted a non-REST publish or
 *                schedule to pending at save time (inc/gate.php).
 *
 * Recording only the first is not enough. The second left a five-minute notice
 * transient and nothing else, and on the block editor that transient is eaten
 * by the meta-box save's own background redirect before a person can see it —
 * so a demotion there would be completely silent.
 *
 * The record is what the Publishing Issues screen lists, what the editor panel
 * reads back over REST, and what the notification email describes.
 *
 * **Written in one place, after the save (2e).** Every path that stops an
 * article only queues what it decided (rendar_pc_queue_issue());
 * rendar_pc_finalize_issue() records it and sends the email once the save it
 * belongs to is over, from an uncached read of the status. So the record and
 * the email say what the row says after every listener has had the post, not
 * what the path intended.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

const RENDAR_PC_ISSUE_SOURCE_CRON     = 'cron';
const RENDAR_PC_ISSUE_SOURCE_BACKSTOP = 'backstop';

/**
 * Build and store the issue record for a post that did not go live.
 *
 * @param int    $post_id    Post ID.
 * @param string $source     RENDAR_PC_ISSUE_SOURCE_* constant.
 * @param array  $report     Evaluation report.
 * @param array  $failing    Check IDs that stopped it.
 * @param array  $context    `intended_status`, `scheduled` (GMT), `user_id` (acting user, 0 for cron),
 *                           `held` and `stored_status` (measured by the finalizer after the save:
 *                           held only when `pending` was read back), `path`, `publish_blocked`.
 * @return array The stored record.
 */
function rendar_pc_record_issue( $post_id, $source, array $report, array $failing, array $context ) {
	$labels   = array();
	$messages = array();

	foreach ( $report['checks'] as $check ) {
		if ( in_array( $check['id'], $failing, true ) ) {
			$labels[]   = $check['label'];
			$messages[] = sprintf( '%s: %s', $check['label'], $check['message'] );
		}
	}

	$record = array(
		'time'            => current_time( 'mysql', true ),
		'source'          => $source,
		'user_id'         => isset( $context['user_id'] ) ? (int) $context['user_id'] : 0,
		'intended_status' => isset( $context['intended_status'] ) ? (string) $context['intended_status'] : 'publish',
		'scheduled'       => isset( $context['scheduled'] ) ? (string) $context['scheduled'] : '',
		'checks'          => array_values( $failing ),
		'labels'          => $labels,
		'messages'        => $messages,
		// What actually happened to the post, as measured by the caller's
		// uncached read (rendar_pc_finalize_issue()). Never assumed: a
		// caller that did not measure gets "not held, status unknown" (2e).
		'held'            => ! empty( $context['held'] ),
		'stored_status'   => isset( $context['stored_status'] ) ? (string) $context['stored_status'] : '',
		'path'            => isset( $context['path'] ) ? (string) $context['path'] : '',
		'publish_blocked' => ! empty( $context['publish_blocked'] ),
	);

	update_post_meta( $post_id, RENDAR_PC_META_SCHEDULE_FAILURE, $record );

	return $record;
}

/**
 * Read a post's issue record, normalised so rows without source/user read cleanly.
 *
 * A row with no `held` (written before 2c) is not assumed held: its outcome is
 * read from the post's status now, which is the only measurement there is.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function rendar_pc_get_issue( $post_id ) {
	$raw = get_post_meta( $post_id, RENDAR_PC_META_SCHEDULE_FAILURE, true );

	if ( ! is_array( $raw ) ) {
		return null;
	}

	if ( ! array_key_exists( 'held', $raw ) ) {
		$status               = (string) get_post_status( $post_id );
		$raw['held']          = 'pending' === $status;
		$raw['stored_status'] = $status;
	}

	return array_merge(
		array(
			'time'            => '',
			'source'          => RENDAR_PC_ISSUE_SOURCE_CRON, // A record without a source came from cron.
			'user_id'         => 0,
			'intended_status' => 'publish',
			'scheduled'       => '',
			'checks'          => array(),
			'labels'          => array(),
			'messages'        => array(),
			'stored_status'   => '',
			'path'            => '',
			'publish_blocked' => false,
		),
		$raw
	);
}

/**
 * Per-request state of the issue finalizer: queued intents, and open writes.
 *
 * `intents`: post ID => what a demotion path decided and tried (0 for a
 * brand-new post whose ID is not known yet). `depth`: post ID => number of
 * wp_insert_post() calls for that post that have passed wp_insert_post_data
 * and not yet reached wp_after_insert_post. `dispatch`: post ID => number of
 * `publish_future_post` dispatches for that post that have started and not
 * yet ended. Internal; returned by reference so the standalone tests
 * can reset it between cases.
 *
 * @return array
 */
function &rendar_pc_issue_state() {
	static $state = array(
		'intents'  => array(),
		'depth'    => array(),
		'dispatch'   => array(),
		'incomplete' => array(), // Post-scoped, live only through its cron dispatch.
	);

	return $state;
}

/**
 * Queue a publishing issue to be recorded once the save it belongs to is over.
 *
 * **2e: one finalization point for every demotion path**.
 * The save-time backstop, the stored-state check, the cron re-check and the
 * transition fallback used to record and email the moment they had decided,
 * from inside a dispatch that was still running: the backstop at
 * `transition_post_status` PHP_INT_MIN, the others straight after their hold.
 * Any listener later in the same save — the rest of that transition,
 * `save_post`, `wp_insert_post`, `wp_after_insert_post` — could reopen the post
 * to `future` or `publish` afterwards, and the record and email then described
 * a hold that did not exist.
 *
 * Now every path only queues its intent here, and rendar_pc_finalize_issue()
 * writes the record and sends the email once the outer save has completed:
 *
 * - a save: `wp_after_insert_post` at PHP_INT_MAX of the OUTERMOST write of
 *   that post (writes are counted from the last `wp_insert_post_data` filter to
 *   `wp_after_insert_post`, so a nested re-save by a listener does not end it);
 * - the cron re-check: the end of the `publish_future_post` dispatch
 *   (PHP_INT_MAX), after core's own publisher at 10 has had the post. An
 *   intent queued while that post's dispatch is running — the re-check's own,
 *   or the fallback's inside it — is owned by the dispatch: the end of
 *   a same-post write nested inside the dispatch does NOT finalize it, because
 *   the dispatch, and whatever reopens the post later in it, is not over;
 * - `shutdown`: anything neither of those reached — a write that died between
 *   the filter and wp_after_insert_post, a wp_die() in the middle of a save.
 *
 * Finalizing reads the status uncached, and the record says what that read
 * found. Nothing here is ever recorded as held from intent.
 *
 * @param int   $post_id          Post ID; 0 for a new post (bound to its ID at its first transition).
 * @param array $intent           `source`, `path`, `intended_status`, `scheduled`, `user_id`, `report`,
 *                                `failing`, `post_type`; `publish_blocked` on the cron path.
 * @param bool  $awaits_after_insert True when called from inside a status change that will still
 *                                reach wp_after_insert_post without having passed wp_insert_post_data
 *                                (wp_publish_post(): the transition fallback).
 * @return void
 */
function rendar_pc_queue_issue( $post_id, array $intent, $awaits_after_insert = false ) {
	$state   = &rendar_pc_issue_state();
	$post_id = (int) $post_id;

	// Owned by the cron dispatch: queued while
	// this post's publish_future_post dispatch is running, or by the re-check
	// that only ever runs inside one, or replacing an intent that already was.
	// Ownership is sticky for the life of the intent.
	$dispatch_owned = ! empty( $intent['dispatch_owned'] )
		|| ( $post_id > 0 && ! empty( $state['dispatch'][ $post_id ] ) )
		|| ! empty( $state['intents'][ $post_id ]['dispatch_owned'] );

	$state['intents'][ $post_id ] = array_merge(
		array(
			'source'          => RENDAR_PC_ISSUE_SOURCE_BACKSTOP,
			'path'            => '',
			'intended_status' => 'publish',
			'scheduled'       => '',
			'user_id'         => 0,
			'report'          => array( 'checks' => array() ),
			'failing'         => array(),
			'post_type'       => '',
			'publish_blocked' => false,
		),
		$intent,
		array( 'dispatch_owned' => $dispatch_owned )
	);

	// wp_publish_post() writes with $wpdb and fires no insert filter, so its
	// own wp_after_insert_post was never counted in. Count it now, unless a
	// counted write of this post is already open (the fallback reached through
	// a wp_update_post() instead), so a nested re-save by a later publish
	// listener cannot be mistaken for the end of the outer one.
	if ( $awaits_after_insert && $post_id > 0 && empty( $state['depth'][ $post_id ] ) ) {
		$state['depth'][ $post_id ] = 1;
	}

	if ( false === has_action( 'shutdown', 'rendar_pc_finalize_leftover_issues' ) ) {
		add_action( 'shutdown', 'rendar_pc_finalize_leftover_issues', PHP_INT_MAX );
	}
}

/**
 * The intent queued for a post and not yet finalized, if any.
 *
 * @param int $post_id Post ID (0: a new post not yet bound).
 * @return array|null
 */
function rendar_pc_pending_issue( $post_id ) {
	$state = &rendar_pc_issue_state();

	return isset( $state['intents'][ (int) $post_id ] ) ? $state['intents'][ (int) $post_id ] : null;
}

/**
 * Count a write of a post as open. Last on wp_insert_post_data.
 *
 * Always on, for every post with an ID: an intent can be queued in the middle
 * of a write that started before it (the backstop inside a save_post
 * callback's re-save, the stored-state check inside wp_after_insert_post), and
 * only a count that was already running knows that write is still open.
 *
 * @param array $data    Post data.
 * @param array $postarr Raw post array.
 * @return array Unchanged.
 */
function rendar_pc_issue_write_enter( $data, $postarr ) {
	$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;

	if ( $post_id > 0 ) {
		$state                      = &rendar_pc_issue_state();
		$state['depth'][ $post_id ] = ( isset( $state['depth'][ $post_id ] ) ? $state['depth'][ $post_id ] : 0 ) + 1;
	}

	return $data;
}
add_filter( 'wp_insert_post_data', 'rendar_pc_issue_write_enter', PHP_INT_MAX, 2 );

/**
 * Give a new post's queued intent its ID.
 *
 * The backstop decides inside wp_insert_post_data, before a new post has an
 * ID. Its first transition (`new` -> anything) is the first moment the ID is
 * known; at PHP_INT_MIN nothing else in that save has run yet, so a nested
 * insert cannot be mistaken for this one. Bound whatever the new status is:
 * whether the demotion held is for the finalizer to measure, not for this.
 * That insert had no ID when it passed wp_insert_post_data, so it was never
 * counted open; it is counted now, and its own wp_after_insert_post closes it.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_issue_bind_new_post( $new_status, $old_status, $post ) {
	unset( $new_status );

	if ( 'new' !== $old_status || ! $post instanceof WP_Post ) {
		return;
	}

	$state = &rendar_pc_issue_state();

	if ( ! isset( $state['intents'][0] ) || $state['intents'][0]['post_type'] !== $post->post_type || isset( $state['intents'][ $post->ID ] ) ) {
		return;
	}

	$state['intents'][ $post->ID ] = $state['intents'][0];
	unset( $state['intents'][0] );

	$state['depth'][ $post->ID ] = ( isset( $state['depth'][ $post->ID ] ) ? $state['depth'][ $post->ID ] : 0 ) + 1;
}
add_action( 'transition_post_status', 'rendar_pc_issue_bind_new_post', PHP_INT_MIN, 3 );

/**
 * A write of a post has fully landed: finalize its issue if this was the outermost one.
 *
 * PHP_INT_MAX, so every other wp_after_insert_post listener — the stored-state
 * check at 999 included — has had the post first.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function rendar_pc_issue_write_leave( $post_id ) {
	$state   = &rendar_pc_issue_state();
	$post_id = (int) $post_id;

	if ( ! empty( $state['depth'][ $post_id ] ) ) {
		--$state['depth'][ $post_id ];

		if ( $state['depth'][ $post_id ] > 0 ) {
			return;
		}
	}

	unset( $state['depth'][ $post_id ] );

	// A cron-owned intent is not this write's to finalize: a same-post save
	// nested inside the publish_future_post dispatch ends long before the
	// dispatch does, and a later callback in it can still reopen the post.
	// Consuming it here recorded held=true and emailed "did not publish" for
	// a post that was `publish` by the end of the dispatch.
	if ( isset( $state['intents'][ $post_id ] ) && empty( $state['intents'][ $post_id ]['dispatch_owned'] ) ) {
		rendar_pc_finalize_issue( $post_id );
	}
}
add_action( 'wp_after_insert_post', 'rendar_pc_issue_write_leave', PHP_INT_MAX, 1 );

/**
 * A `publish_future_post` dispatch for a post has started. First on the hook.
 *
 * From here to rendar_pc_finalize_after_future_dispatch() every intent
 * queued for this post is owned by the dispatch.
 *
 * @param int $post_id Post ID from the cron event.
 * @return void
 */
function rendar_pc_future_dispatch_enter( $post_id ) {
	$state   = &rendar_pc_issue_state();
	$post_id = (int) $post_id;

	if ( $post_id > 0 ) {
		$state['dispatch'][ $post_id ] = ( isset( $state['dispatch'][ $post_id ] ) ? $state['dispatch'][ $post_id ] : 0 ) + 1;
		// An exception before the PHP_INT_MAX leave hook can strand an incomplete
		// marker even when no issue was queued. Register recovery at entry.
		if ( false === has_action( 'shutdown', 'rendar_pc_finalize_leftover_issues' ) ) {
			add_action( 'shutdown', 'rendar_pc_finalize_leftover_issues', PHP_INT_MAX );
		}
	}
}
add_action( 'publish_future_post', 'rendar_pc_future_dispatch_enter', PHP_INT_MIN, 1 );

/**
 * The cron re-check's dispatch is over: finalize what it queued.
 *
 * Last on `publish_future_post`, after core's check_and_publish_future_post()
 * at 10 has found the post held (or published it, if something reopened it in
 * between — which the read then reports). This is the only place a
 * dispatch-owned intent is finalized, bar the shutdown net; a dispatch nested
 * inside another for the same post leaves it to the outer one.
 *
 * @param int $post_id Post ID from the cron event.
 * @return void
 */
function rendar_pc_finalize_after_future_dispatch( $post_id ) {
	$state   = &rendar_pc_issue_state();
	$post_id = (int) $post_id;

	if ( ! empty( $state['dispatch'][ $post_id ] ) ) {
		--$state['dispatch'][ $post_id ];

		if ( $state['dispatch'][ $post_id ] > 0 ) {
			return;
		}
	}

	unset( $state['dispatch'][ $post_id ], $state['incomplete'][ $post_id ] );

	if ( null !== rendar_pc_pending_issue( $post_id ) ) {
		rendar_pc_finalize_issue( $post_id );
	}
}
add_action( 'publish_future_post', 'rendar_pc_finalize_after_future_dispatch', PHP_INT_MAX, 1 );

/**
 * Shutdown safety net: finalize every intent nothing else reached.
 *
 * An intent still waiting for a new post's ID belongs to an insert that never
 * happened; there is no row to describe, so it is dropped.
 *
 * @return void
 */
function rendar_pc_finalize_leftover_issues() {
	$state = &rendar_pc_issue_state();

	unset( $state['intents'][0] );

	foreach ( array_keys( $state['intents'] ) as $post_id ) {
		rendar_pc_finalize_issue( $post_id );
	}

	// A callback can throw before the dispatch leave hook. Keep the marker
	// scoped to that dispatch, not to every later publish in this request.
	$state['dispatch']   = array();
	$state['incomplete'] = array();
}

/**
 * A post's status as it is stored, read from the database.
 *
 * Not get_post_status(): that answers from the object cache, and the whole
 * point of this read is to catch a write that did not land or was undone. The
 * post cache is cleaned as well, so everything after this — core's own
 * get_post() in check_and_publish_future_post() included — sees the same row.
 *
 * @param int $post_id Post ID.
 * @return string Stored status, '' when there is no such row.
 */
function rendar_pc_stored_status( $post_id ) {
	global $wpdb;

	clean_post_cache( (int) $post_id );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberately uncached: this verifies a write.
	$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", (int) $post_id ) );

	return null === $status ? '' : (string) $status;
}

/**
 * Record, and announce, one queued issue — as the post actually ended up.
 *
 * 1. Read the status uncached. `held` is true only when that read says
 *    `pending`; anything else is recorded not held, with the status found.
 * 2. Write the issue record from that read.
 * 3. The one-shot admin notice for a save-time path, worded from the record.
 * 4. `rendar_prepublish_checks_issue_finalized` — what inc/notify.php emails
 *    from, so the email is the held one or the "check it now" one according
 *    to the same read. The email claim stays atomic across requests.
 *
 * Then the two narrower actions, kept for listeners: `schedule_failed` for every
 * cron-source record (its record argument says whether it held), and
 * `backstop_demoted` only when the demotion actually held, which is what its
 * name promises.
 *
 * @param int $post_id Post ID.
 * @return array|null The stored record; null when nothing was queued or the post no longer exists.
 */
function rendar_pc_finalize_issue( $post_id ) {
	$state   = &rendar_pc_issue_state();
	$post_id = (int) $post_id;

	if ( $post_id <= 0 || ! isset( $state['intents'][ $post_id ] ) ) {
		return null;
	}

	$intent = $state['intents'][ $post_id ];
	unset( $state['intents'][ $post_id ] );

	$stored = rendar_pc_stored_status( $post_id );

	if ( '' === $stored ) {
		return null;
	}

	$scheduled = (string) $intent['scheduled'];

	if ( '' === $scheduled && 'future' === $intent['intended_status'] ) {
		$post      = get_post( $post_id );
		$scheduled = $post instanceof WP_Post ? (string) $post->post_date_gmt : '';
	}

	$record = rendar_pc_record_issue(
		$post_id,
		$intent['source'],
		$intent['report'],
		$intent['failing'],
		array(
			'intended_status' => $intent['intended_status'],
			'scheduled'       => $scheduled,
			'user_id'         => $intent['user_id'],
			'held'            => 'pending' === $stored,
			'stored_status'   => $stored,
			'path'            => $intent['path'],
			'publish_blocked' => ! empty( $intent['publish_blocked'] ),
		)
	);

	if ( RENDAR_PC_ISSUE_SOURCE_BACKSTOP === $record['source'] && $record['user_id'] ) {
		set_transient(
			rendar_pc_notice_transient_key( $record['user_id'] ),
			array(
				'post_id'       => $post_id,
				'messages'      => $record['messages'],
				'held'          => $record['held'],
				'stored_status' => $record['stored_status'],
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Fires once per publishing issue, after the save it belongs to is over.
	 *
	 * The record is measured, never assumed: `held` is true only when the
	 * uncached status read taken here said `pending`. inc/notify.php sends the
	 * failure email from this action.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $post_id Post ID.
	 * @param array $record  The stored issue record.
	 * @param array $report  Full evaluation report.
	 */
	do_action( 'rendar_prepublish_checks_issue_finalized', $post_id, $record, $intent['report'] );

	if ( RENDAR_PC_ISSUE_SOURCE_CRON === $record['source'] ) {
		/**
		 * Fires when a scheduled post failed its checks at publish time.
		 *
		 * The fourth argument says what actually happened, measured after the
		 * dispatch: `held` (pending is in the row), `stored_status`, `path`
		 * (`cron-event` or `publish-transition`) and, on the cron path when the
		 * hold did not land, `publish_blocked`.
		 *
		 * @param int      $post_id    Post ID.
		 * @param string[] $unresolved Failing check IDs.
		 * @param array    $report     Full evaluation report.
		 * @param array    $record     The stored issue record (2c).
		 */
		do_action( 'rendar_prepublish_checks_schedule_failed', $post_id, $intent['failing'], $intent['report'], $record );
	} elseif ( $record['held'] ) {
		/**
		 * Fires when a save-time check demoted a publish or schedule to pending, and it held.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $post_id         Post ID.
		 * @param array  $failing         Failing check IDs.
		 * @param array  $report          Full evaluation report.
		 * @param string $intended_status The status the write asked for (publish, future, private).
		 * @param array  $record          The stored issue record.
		 */
		do_action( 'rendar_prepublish_checks_backstop_demoted', $post_id, $intent['failing'], $intent['report'], $intent['intended_status'], $record );
	}

	return $record;
}

/**
 * Remember who scheduled a post, so the failure email can reach them.
 *
 * The post_author is not the answer on many editorial sites: authors save to
 * pending and an editor schedules. Only a real user moving a post INTO `future` is recorded —
 * cron, WP-CLI and the meta-box follow-up (future -> future) never are.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_report_is_complete( $report ) {
	if ( ! is_array( $report ) || ! isset( $report['checks'] ) || ! is_array( $report['checks'] ) ) {
		return false;
	}
	foreach ( $report['checks'] as $check ) {
		if ( isset( $check['status'] ) && RENDAR_PC_STATUS_UNAVAILABLE === $check['status'] ) {
			return false;
		}
	}
	return true;
}

function rendar_pc_record_scheduler( $new_status, $old_status, $post ) {
	if ( 'future' !== $new_status || 'future' === $old_status || ! $post instanceof WP_Post ) {
		return;
	}

	if ( ! in_array( $post->post_type, rendar_pc_get_post_types(), true ) ) {
		return;
	}

	$user_id = get_current_user_id();

	if ( $user_id > 0 ) {
		update_post_meta( $post->ID, RENDAR_PC_META_SCHEDULED_BY, $user_id );
	}
}
add_action( 'transition_post_status', 'rendar_pc_record_scheduler', 30, 3 );

/**
 * Clear the issue once the article is actually live.
 *
 * Covers every publishing path — REST, classic, Quick Edit, cron — rather than
 * only the REST one (rendar_pc_after_rest_insert keeps its own clear for the
 * case where a REST save publishes without a transition). A post the re-check is
 * in the middle of reverting is skipped: the outer future -> publish transition
 * is still being dispatched after the revert, and must not wipe the record the
 * revert has just written.
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post.
 * @return void
 */
function rendar_pc_clear_issue_on_publish( $new_status, $old_status, $post ) {
	unset( $old_status );

	if ( ! $post instanceof WP_Post || ! in_array( $new_status, array( 'publish', 'private' ), true ) ) {
		return;
	}

	if ( rendar_pc_was_reverted( $post->ID ) ) {
		return;
	}

	// An unavailable cron evaluation cannot resolve an old issue merely
	// because core published the post later in the same dispatch.
	$state = &rendar_pc_issue_state();
	if ( ! empty( $state['dispatch'][ $post->ID ] ) && ! empty( $state['incomplete'][ $post->ID ] ) ) {
		return;
	}

	// A failure recorded in this request stands, including the one case where
	// the post really is published: the fallback could not take it back down,
	// and the record saying so is what tells someone to go and look.
	if ( function_exists( 'rendar_pc_failure_recorded_registry' ) && in_array( (int) $post->ID, rendar_pc_failure_recorded_registry(), true ) ) {
		return;
	}

	// The row, not the dispatched object: a revert inside this same dispatch
	// leaves the object saying "publish" after the database says "pending".
	if ( ! in_array( get_post_status( $post->ID ), array( 'publish', 'private' ), true ) ) {
		return;
	}

	// A REST verdict with an unavailable check cannot resolve a recorded
	// failure just because the status changed. The check never ran.
	if ( is_array( $GLOBALS['rendar_pc_rest_verdict'] ?? null ) && ! rendar_pc_report_is_complete( $GLOBALS['rendar_pc_rest_verdict'] ) ) {
		return;
	}
	delete_post_meta( $post->ID, RENDAR_PC_META_SCHEDULE_FAILURE );
}
add_action( 'transition_post_status', 'rendar_pc_clear_issue_on_publish', 40, 3 );

/**
 * Shape an issue for the editor.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function rendar_pc_issue_for_editor( $post_id ) {
	$issue  = rendar_pc_get_issue( $post_id );
	$status = get_post_status( $post_id );

	if ( ! $issue ) {
		return array(
			'post_status' => $status,
			'issue'       => null,
			'active'      => false,
		);
	}

	$user = $issue['user_id'] ? get_userdata( $issue['user_id'] ) : false;

	return array(
		'post_status' => $status,
		'active'      => in_array( $status, array( 'pending', 'draft' ), true ),
		'issue'       => array(
			'source'            => $issue['source'],
			'time'              => $issue['time'],
			'time_display'      => $issue['time'] ? get_date_from_gmt( $issue['time'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
			'intended_status'   => $issue['intended_status'],
			'scheduled'         => $issue['scheduled'],
			'scheduled_display' => $issue['scheduled'] ? get_date_from_gmt( $issue['scheduled'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
			'user'              => $user ? $user->display_name : '',
			'checks'            => $issue['checks'],
			'messages'          => $issue['messages'],
			'held'              => (bool) $issue['held'],
			'stored_status'     => (string) $issue['stored_status'],
		),
	);
}
