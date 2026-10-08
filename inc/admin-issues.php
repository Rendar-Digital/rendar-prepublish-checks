<?php
/**
 * Publishing Issues: the queue of articles that were meant to go live and did not.
 *
 * Two sources land here (inc/issues.php): a scheduled post that failed its
 * re-check at publish time and was taken back down to pending (`cron`), and a
 * non-REST publish or schedule the save-time backstop demoted to pending
 * (`backstop`). The whole risk of both is silence — an article that was supposed
 * to go live at 9am sitting in pending with nobody told. This screen, the notice
 * beside it, the editor panel and the failure email are the answer to that.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the Publishing Issues screen under Posts.
 *
 * @return void
 */
function rendar_pc_add_issues_page() {
	$count = count( rendar_pc_get_failed_scheduled_posts( 100 ) );

	$title = $count
		? sprintf(
			/* translators: %s: number of articles. */
			__( 'Publishing Issues (%s)', 'rendar-prepublish-checks' ),
			number_format_i18n( $count )
		)
		: __( 'Publishing Issues', 'rendar-prepublish-checks' );

	add_submenu_page(
		'edit.php',
		__( 'Publishing Issues', 'rendar-prepublish-checks' ),
		$title,
		'edit_others_posts',
		'rendar-pc-issues',
		'rendar_pc_render_issues_page'
	);
}
add_action( 'admin_menu', 'rendar_pc_add_issues_page' );

/**
 * Render the Publishing Issues screen.
 *
 * @return void
 */
function rendar_pc_render_issues_page() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to view this page.', 'rendar-prepublish-checks' ) );
	}

	$posts = rendar_pc_get_failed_scheduled_posts( 100 );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Publishing Issues', 'rendar-prepublish-checks' ); ?></h1>

		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'These articles were meant to go live and did not: either they were scheduled and failed their checks at the scheduled time, or someone tried to publish or schedule them from outside the block editor (Quick Edit, Bulk Edit, the classic editor) and they failed their checks at that moment. Each was moved back to pending rather than going live, unless it is marked NOT HELD BACK below. Fix what is listed and publish or reschedule them.', 'rendar-prepublish-checks' ); ?>
		</p>

		<?php if ( ! $posts ) : ?>
			<p><strong><?php esc_html_e( 'Nothing here. Every article that was meant to go live has.', 'rendar-prepublish-checks' ); ?></strong></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Article', 'rendar-prepublish-checks' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Author', 'rendar-prepublish-checks' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Was due', 'rendar-prepublish-checks' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stopped by', 'rendar-prepublish-checks' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What failed', 'rendar-prepublish-checks' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $posts as $post ) : ?>
				<?php
				$failure  = rendar_pc_get_issue( $post->ID );
				$messages = $failure && is_array( $failure['messages'] ) ? $failure['messages'] : array();
				$due      = $failure ? $failure['scheduled'] : '';
				$edit     = get_edit_post_link( $post->ID );
				$actor    = $failure && $failure['user_id'] ? get_userdata( $failure['user_id'] ) : false;
				$when     = $failure && $failure['time'] ? get_date_from_gmt( $failure['time'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
				?>
				<tr>
					<td>
						<?php if ( $failure && ! $failure['held'] ) : ?>
							<strong style="color:#b32d2e">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: post status. */
										__( 'NOT HELD BACK (status: %s). Check it now.', 'rendar-prepublish-checks' ),
										$failure['stored_status'] ? $failure['stored_status'] : __( 'unknown', 'rendar-prepublish-checks' )
									)
								);
								?>
							</strong><br />
						<?php endif; ?>
						<?php if ( $edit ) : ?>
							<a href="<?php echo esc_url( $edit ); ?>"><strong><?php echo esc_html( get_the_title( $post ) ); ?></strong></a>
						<?php else : ?>
							<strong><?php echo esc_html( get_the_title( $post ) ); ?></strong>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( get_the_author_meta( 'display_name', $post->post_author ) ); ?></td>
					<td>
						<?php
						echo $due
							? esc_html( get_date_from_gmt( $due, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
							: '&mdash;';
						?>
					</td>
					<td>
						<?php
						if ( $failure && RENDAR_PC_ISSUE_SOURCE_BACKSTOP === $failure['source'] ) {
							echo esc_html(
								sprintf(
									/* translators: 1: user display name, 2: date and time. */
									__( 'Save-time check: %1$s tried to publish or schedule it, %2$s', 'rendar-prepublish-checks' ),
									$actor ? $actor->display_name : __( 'someone', 'rendar-prepublish-checks' ),
									$when
								)
							);
						} else {
							echo esc_html(
								sprintf(
									/* translators: %s: date and time. */
									__( 'Scheduled-publish check, %s', 'rendar-prepublish-checks' ),
									$when
								)
							);
						}
						?>
					</td>
					<td>
						<ul style="margin:0">
						<?php foreach ( $messages as $message ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php endforeach; ?>
						</ul>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Notices: the Quick Edit demotion, and the scheduled-publish queue.
 *
 * @return void
 */
function rendar_pc_admin_notices() {
	rendar_pc_render_backstop_notice();
	rendar_pc_render_queue_notice();
}
add_action( 'admin_notices', 'rendar_pc_admin_notices' );

/**
 * Tell the user their non-REST publish was demoted to pending.
 *
 * @return void
 */
function rendar_pc_render_backstop_notice() {
	// Never on the block editor screen. The meta-box follow-up's own redirect
	// loads this page in the background, in a response nobody sees, and would
	// consume the one-shot notice there (an earlier design did). The editor reads
	// the demotion from the post's issue record over REST instead, and that
	// read clears this transient.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return;
	}

	$key    = rendar_pc_notice_transient_key( get_current_user_id() );
	$notice = get_transient( $key );

	if ( ! is_array( $notice ) ) {
		return;
	}

	delete_transient( $key );

	$messages = isset( $notice['messages'] ) && is_array( $notice['messages'] ) ? $notice['messages'] : array();

	// Written by rendar_pc_finalize_issue() after the save, from an
	// uncached read (2e). A notice without a measured `held` is not assumed
	// held.
	$held   = ! empty( $notice['held'] );
	$status = isset( $notice['stored_status'] ) && '' !== (string) $notice['stored_status'] ? (string) $notice['stored_status'] : __( 'unknown', 'rendar-prepublish-checks' );

	?>
	<div class="notice notice-error">
		<p>
			<?php if ( $held ) : ?>
				<strong><?php esc_html_e( 'This article was not published.', 'rendar-prepublish-checks' ); ?></strong>
				<?php esc_html_e( 'It has been saved as pending instead, because it did not pass the pre-publish checks:', 'rendar-prepublish-checks' ); ?>
			<?php else : ?>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: post status. */
							__( 'This article failed its pre-publish checks and could NOT be held back (status: %s). Check it now.', 'rendar-prepublish-checks' ),
							$status
						)
					);
					?>
				</strong>
				<?php esc_html_e( 'Moving it to pending did not stick. What failed:', 'rendar-prepublish-checks' ); ?>
			<?php endif; ?>
		</p>
		<ul style="list-style:disc;margin-left:2em">
			<?php foreach ( $messages as $message ) : ?>
				<li><?php echo esc_html( $message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

/**
 * Point at the queue whenever anything is in it.
 *
 * Shown on the posts screens only. A notice on every admin page for a state
 * that is someone's job to clear becomes wallpaper within a week.
 *
 * @return void
 */
function rendar_pc_render_queue_notice() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! in_array( $screen->id, array( 'edit-post', 'dashboard' ), true ) ) {
		return;
	}

	$count = count( rendar_pc_get_failed_scheduled_posts( 100 ) );

	if ( ! $count ) {
		return;
	}

	?>
	<div class="notice notice-warning">
		<p>
			<?php
			printf(
				esc_html(
					/* translators: %s: number of articles. */
					_n(
						'%s article did not go live because it failed its pre-publish checks.',
						'%s articles did not go live because they failed their pre-publish checks.',
						$count,
						'rendar-prepublish-checks'
					)
				),
				esc_html( number_format_i18n( $count ) )
			);
			?>
			<a href="<?php echo esc_url( admin_url( 'edit.php?page=rendar-pc-issues' ) ); ?>">
				<?php esc_html_e( 'Review them', 'rendar-prepublish-checks' ); ?>
			</a>
		</p>
	</div>
	<?php
}
