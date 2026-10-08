<?php
/**
 * Email when an article that was meant to go live did not.
 *
 * The failure mode this plugin is most exposed to is silence: a 9am article
 * sitting in pending with nobody told. The Publishing Issues screen answers that
 * for whoever opens it; this answers it for whoever did not.
 *
 * One trigger (2e): `rendar_prepublish_checks_issue_finalized`, fired once per
 * publishing issue by rendar_pc_finalize_issue() (inc/issues.php) after the
 * save or cron dispatch it belongs to is over. The record it carries was
 * measured by an uncached status read at that point, and the email is chosen
 * from it: the "moved back to Pending" email only when the row said `pending`,
 * otherwise the urgent "could not be held back — check it now" one, with the
 * status found. Every cron-source issue emails; a save-time issue emails when
 * the article was being published or scheduled (a `private` target is recorded
 * but does not email).
 *
 * Until 2e there were two triggers, fired from inside the save by the path
 * that had decided — so a listener later in the same save could reopen the
 * post after the email had already said it was held.
 *
 * Plain wp_mail(), plain text. It is a site system email, sent from the site.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * How long an identical failure is considered already reported.
 *
 * Long enough to swallow the block editor's double POST and any re-entrant
 * save; short enough that a second, separate attempt that fails the same way an
 * hour later is reported again.
 */
const RENDAR_PC_NOTIFY_DEDUPE_SECONDS = 30 * MINUTE_IN_SECONDS;

/**
 * Email a finalized publishing issue.
 *
 * @param int   $post_id Post ID.
 * @param array $record  Stored issue record, measured after the save (2e).
 * @param array $report  Evaluation report.
 * @return void
 */
function rendar_pc_notify_issue_finalized( $post_id, $record, $report ) {
	unset( $report );

	if ( ! is_array( $record ) ) {
		return;
	}

	if ( RENDAR_PC_ISSUE_SOURCE_CRON !== $record['source'] && ! in_array( $record['intended_status'], array( 'publish', 'future' ), true ) ) {
		return;
	}

	rendar_pc_send_failure_email( (int) $post_id, $record );
}
add_action( 'rendar_prepublish_checks_issue_finalized', 'rendar_pc_notify_issue_finalized', 10, 3 );

/**
 * Who gets the email.
 *
 * @param int   $post_id Post ID.
 * @param array $issue   Issue record.
 * @return string[] Lower-cased, de-duplicated addresses.
 */
function rendar_pc_failure_recipients( $post_id, array $issue ) {
	$settings   = rendar_pc_get_settings();
	$recipients = is_array( $settings['notify_recipients'] ) ? $settings['notify_recipients'] : array();

	if ( ! empty( $settings['notify_scheduler'] ) ) {
		// The person who pressed the button: the acting user for a save-time
		// demotion; for a cron revert, whoever moved the post into `future`.
		$user_id = (int) $issue['user_id'];

		if ( ! $user_id ) {
			$user_id = (int) get_post_meta( $post_id, RENDAR_PC_META_SCHEDULED_BY, true );
		}

		$user = $user_id ? get_userdata( $user_id ) : false;

		if ( $user && is_email( $user->user_email ) ) {
			$recipients[] = $user->user_email;
		}
	}

	$clean = array_flip( rendar_pc_clean_recipient_list( array_map( 'trim', array_filter( $recipients, 'is_string' ) ) ) );

	/**
	 * Filters the recipients of the publishing-failure email.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $recipients Addresses.
	 * @param int      $post_id    Post ID.
	 * @param array    $issue      Issue record.
	 */
	$filtered = (array) apply_filters( 'rendar_prepublish_checks_failure_recipients', array_keys( $clean ), $post_id, $issue );

	// Validated again after the filter: whatever a callback returns goes
	// straight to wp_mail(), and an entry carrying CR/LF or a second address
	// must never reach it.
	return rendar_pc_clean_recipient_list( $filtered );
}

/**
 * Keep only single, valid, header-safe addresses.
 *
 * An entry is dropped, never repaired: "a@b.com\r\nBcc: c@d.com" is not
 * turned into a@b.com. is_email() already rejects CR, LF, spaces, commas and
 * angle brackets; the control-character test is the second fence, because this
 * list is what reaches the To: header.
 *
 * @param array $addresses Candidate addresses.
 * @return string[] Lower-cased, de-duplicated.
 */
function rendar_pc_clean_recipient_list( array $addresses ) {
	$clean = array();

	foreach ( $addresses as $address ) {
		if ( ! is_string( $address ) || preg_match( '/[\x00-\x1F\x7F,;<>\s]/', $address ) ) {
			continue;
		}

		$address = strtolower( $address );

		if ( is_email( $address ) ) {
			$clean[ $address ] = true;
		}
	}

	return array_keys( $clean );
}

/**
 * Stable identity of one failure, for de-duplication.
 *
 * @param int   $post_id Post ID.
 * @param array $issue   Issue record.
 * @return string
 */
function rendar_pc_failure_fingerprint( $post_id, array $issue ) {
	$checks = array_map( 'strval', (array) $issue['checks'] );
	sort( $checks );

	$outcome = array(
		(int) ! empty( $issue['held'] ),
		isset( $issue['path'] ) ? (string) $issue['path'] : '',
	);

	// The outcome is part of the identity: "held back" and "could not be held
	// back" about the same post are two different emails, and the first must
	// never swallow the second.
	return md5( implode( '|', array( (int) $post_id, $issue['source'], $issue['intended_status'], $issue['scheduled'], implode( ',', $checks ), implode( ':', $outcome ) ) ) );
}

/**
 * Option-name prefix of a notification claim.
 *
 * `<prefix><md5 fingerprint>` is 55 characters, inside option_name's 191.
 */
const RENDAR_PC_NOTIFY_CLAIM_PREFIX = 'rendar_pc_notified_';

/**
 * Claim the right to send one failure email. Atomic across requests.
 *
 * Two layers, because the duplicates arrive two ways. Within one request the
 * same post can be demoted twice (a save_post callback re-saving it), so a
 * static set answers first. Across requests — the block editor's REST save and
 * its meta-box follow-up, a re-POST, two cron runners — the claim is one row
 * whose uniqueness the DATABASE enforces.
 *
 * Not add_post_meta( ..., true ). That is a SELECT COUNT(*) followed by a
 * separate INSERT (wp-includes/meta.php, add_metadata()), and wp_postmeta has
 * no unique key on (post_id, meta_key): two requests can both count zero and
 * both insert, and both send. Reproduced against MySQL with a forced interleave.
 * wp_options.option_name IS a unique key, so the claim is a single statement
 * against it:
 *
 *   INSERT ... ON DUPLICATE KEY UPDATE option_value = IF(expired, now, option_value)
 *
 * MySQL/MariaDB report 1 affected row for an insert, 2 for an update that
 * changed the row, and 0 for a duplicate left unchanged (wpdb connects without
 * CLIENT_FOUND_ROWS). So 1 is a fresh claim, 2 is re-claiming an expired one,
 * 0 means someone else holds a live claim. The duplicate path takes the row
 * lock, so concurrent claimers serialise on it and exactly one sees 1 or 2.
 *
 * The row is written straight to the table and is never read through
 * get_option(), so it touches neither the alloptions nor the notoptions cache;
 * autoload is 'off'. A failed send conditionally releases its own claim;
 * another request may retry without deleting a newer owner's claim.
 *
 * @param int    $post_id     Post ID (part of the fingerprint already).
 * @param string $fingerprint Failure fingerprint.
 * @return bool True if this call claimed it (send), false if already claimed.
 */
function rendar_pc_claim_failure_notification( $post_id, $fingerprint ) {
	global $wpdb;
	$claimed = &rendar_pc_notification_claims();

	unset( $post_id );

	$fingerprint = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $fingerprint ) );

	if ( '' === $fingerprint || isset( $claimed[ $fingerprint ] ) ) {
		return false;
	}

	$now     = time();
	// Timestamp for expiry; suffix identifies this owner on conditional release.
	$token   = $now . ':' . wp_generate_uuid4();
	$expired = $now - RENDAR_PC_NOTIFY_DEDUPE_SECONDS;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The atomic claim IS the direct query; nothing is cached and no options API call can express it.
	$affected = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')
			ON DUPLICATE KEY UPDATE option_value = IF( CAST( option_value AS UNSIGNED ) <= %d, %s, option_value )",
			RENDAR_PC_NOTIFY_CLAIM_PREFIX . $fingerprint,
			$token,
			$expired,
			$token
		)
	);

	rendar_pc_purge_expired_notification_claims( $expired );

	if ( 1 === $affected || 2 === $affected ) {
		$claimed[ $fingerprint ] = $token;
		return true;
	}
	return false;
}

/** Per-request owners of atomic notification claims. */
function &rendar_pc_notification_claims() {
	static $claimed = array();
	return $claimed;
}

/** Release only this request's uncached claim after a failed send. */
function rendar_pc_release_failure_notification( $fingerprint ) {
	global $wpdb;
	$claimed = &rendar_pc_notification_claims();
	if ( ! isset( $claimed[ $fingerprint ] ) ) {
		return;
	}
	$token = $claimed[ $fingerprint ];
	unset( $claimed[ $fingerprint ] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conditional release of our own uncached claim.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", RENDAR_PC_NOTIFY_CLAIM_PREFIX . $fingerprint, $token ) );
}

/**
 * Delete expired notification claims.
 *
 * Runs only when a failure email is being considered, which is rare, and is
 * bounded so it can never become a long statement.
 *
 * @param int $expired Claims stamped at or before this Unix time are expired.
 * @return void
 */
function rendar_pc_purge_expired_notification_claims( $expired ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Claim rows are never cached (see the claim).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST( option_value AS UNSIGNED ) <= %d LIMIT 100",
			$wpdb->esc_like( RENDAR_PC_NOTIFY_CLAIM_PREFIX ) . '%',
			(int) $expired
		)
	);
}

/**
 * Compose the email.
 *
 * @param int   $post_id Post ID.
 * @param array $issue   Issue record.
 * @return array { subject, message }
 */
function rendar_pc_failure_email( $post_id, array $issue ) {
	$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$title     = wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );
	$title     = '' !== trim( $title ) ? $title : sprintf( '(no title, #%d)', $post_id );
	$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	$scheduled = $issue['scheduled'] ? get_date_from_gmt( $issue['scheduled'], $format ) . ' (' . wp_timezone_string() . ')' : '';
	$edit      = admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	$queue     = admin_url( 'edit.php?page=rendar-pc-issues' );
	$cron      = RENDAR_PC_ISSUE_SOURCE_CRON === $issue['source'];
	$held      = ! empty( $issue['held'] );
	$stored    = isset( $issue['stored_status'] ) ? (string) $issue['stored_status'] : '';
	$via_hook  = isset( $issue['path'] ) && 'publish-transition' === $issue['path'];

	// Every sentence below is chosen from what the record says actually
	// happened. "Moved back to Pending" / "saved as Pending" is only ever
	// written when `pending` was read back from the row after the save (2e).
	if ( ! $held ) {
		$subject = sprintf( '[%s] ACTION NEEDED: article failed its checks and could not be held back: %s', $site, $title );

		if ( $cron && 'publish' === $stored ) {
			$lead = 'This scheduled article was published, failed its pre-publish checks, and could NOT be taken back down. It is LIVE now. Check it now.';
		} elseif ( $cron && ! empty( $issue['publish_blocked'] ) ) {
			$lead = 'This article failed its pre-publish checks at its scheduled time, and it could NOT be held back: moving it to Pending did not work. The scheduled run did not publish it, but the article is still not held back, and anything else that publishes scheduled articles could still publish it. Check it now.';
		} elseif ( $cron ) {
			$lead = 'This article failed its pre-publish checks at its scheduled time, and it could NOT be held back: moving it to Pending did not work. Check it now.';
		} else {
			$tried = 'future' === $issue['intended_status'] ? 'schedule' : 'publish';

			if ( in_array( $stored, array( 'publish', 'private' ), true ) ) {
				$state = 'It is LIVE now.';
			} elseif ( 'future' === $stored ) {
				$state = 'It is still scheduled, and it will go live at its scheduled time unless the scheduled re-check stops it then. Do not rely on that.';
			} else {
				$state = sprintf( 'Its status is now: %s.', '' !== $stored ? $stored : 'unknown' );
			}

			$lead = sprintf( 'Someone tried to %s this article, and it failed its pre-publish checks. It could NOT be held back: moving it to Pending did not stick. %s Check it now.', $tried, $state );
		}
	} elseif ( $cron && $via_hook ) {
		$subject = sprintf( '[%s] Scheduled article was published and taken back down: %s', $site, $title );
		$lead    = 'This scheduled article was published by something other than its schedule (a plugin or WP-CLI), failed its pre-publish checks, and has been taken back down to Pending. It was public for a moment, and publish notifications (social sharing) may already have gone out.';
	} elseif ( $cron ) {
		$subject = sprintf( '[%s] Scheduled article did not publish: %s', $site, $title );
		$lead    = 'This article was scheduled to publish but failed its pre-publish checks at the scheduled time. It was not published; it has been moved back to Pending.';
	} elseif ( 'future' === $issue['intended_status'] ) {
		$subject = sprintf( '[%s] Article was not scheduled: %s', $site, $title );
		$lead    = 'Someone tried to schedule this article, but it failed its pre-publish checks. It was not scheduled; it has been saved as Pending instead.';
	} else {
		$subject = sprintf( '[%s] Article was not published: %s', $site, $title );
		$lead    = 'Someone tried to publish this article, but it failed its pre-publish checks. It was not published; it has been saved as Pending instead.';
	}

	$lines   = array( $lead, '', 'Article: ' . $title, 'Edit: ' . $edit );
	$lines[] = 'Intended publish time: ' . ( $scheduled ? $scheduled : 'immediately' );

	if ( ! $held ) {
		$lines[] = 'Current status: ' . ( '' !== $stored ? $stored : 'unknown (the article could not be read back)' );
	}

	if ( ! $cron && $issue['user_id'] ) {
		$user    = get_userdata( (int) $issue['user_id'] );
		$lines[] = 'Attempted by: ' . ( $user ? $user->display_name : '#' . (int) $issue['user_id'] );
	}

	$lines[] = '';
	$lines[] = 'What failed:';

	foreach ( (array) $issue['messages'] as $message ) {
		$lines[] = '- ' . wp_strip_all_tags( $message );
	}

	$lines[] = '';
	if ( ! $held ) {
		$lines[] = 'What to do: open the article now. Unpublish it or move it to Pending yourself if it is not ready, then fix the items above. The Pre-Publish Checks panel in the editor shows each item.';
		$lines[] = 'Publishing Issues: ' . $queue;
	} else {
		$lines[] = 'What to do: open the article, fix the items above, then schedule or publish it again. The Pre-Publish Checks panel in the editor shows each item.';
		$lines[] = 'All articles held back this way: ' . $queue;
	}
	$lines[] = '';
	$lines[] = '-- ';
	$lines[] = 'Sent automatically by ' . $site . ' (' . home_url() . '). Turn these emails off, or change who receives them, under Settings > Pre-Publish Checks.';

	return array(
		'subject' => $subject,
		'message' => implode( "\n", $lines ),
	);
}

/**
 * Send the failure email once.
 *
 * @param int   $post_id Post ID.
 * @param array $issue   Issue record.
 * @return bool Whether an email was handed to wp_mail().
 */
function rendar_pc_send_failure_email( $post_id, array $issue ) {
	$settings = rendar_pc_get_settings();

	if ( empty( $settings['notify_enabled'] ) ) {
		return false;
	}

	$recipients = rendar_pc_failure_recipients( $post_id, $issue );

	if ( ! $recipients ) {
		return false;
	}

	$fingerprint = rendar_pc_failure_fingerprint( $post_id, $issue );
	if ( ! rendar_pc_claim_failure_notification( $post_id, $fingerprint ) ) {
		return false;
	}

	$email = rendar_pc_failure_email( $post_id, $issue );
	$sent  = (bool) wp_mail( $recipients, $email['subject'], $email['message'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
	if ( ! $sent ) {
		rendar_pc_release_failure_notification( $fingerprint );
	}
	return $sent;
}
