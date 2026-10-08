<?php
/**
 * Settings screen.
 *
 * The severity dropdowns make policy a setting: moving a check between
 * advisory and blocking is a choice a site administrator makes here, not a
 * deploy.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the option.
 *
 * @return void
 */
function rendar_pc_register_settings() {
	register_setting(
		'rendar_pc',
		RENDAR_PC_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'rendar_pc_sanitize_settings',
			'default'           => rendar_pc_default_settings(),
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'rendar_pc_register_settings' );

/**
 * Add the settings page.
 *
 * @return void
 */
function rendar_pc_add_settings_page() {
	add_options_page(
		__( 'Pre-Publish Checks', 'rendar-prepublish-checks' ),
		__( 'Pre-Publish Checks', 'rendar-prepublish-checks' ),
		'manage_options',
		'rendar-pc',
		'rendar_pc_render_settings_page'
	);
}
add_action( 'admin_menu', 'rendar_pc_add_settings_page' );

/**
 * Render the settings page.
 *
 * @return void
 */
function rendar_pc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage these settings.', 'rendar-prepublish-checks' ) );
	}

	$settings = rendar_pc_get_settings();
	$checks   = rendar_pc_get_checks();

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Pre-Publish Checks', 'rendar-prepublish-checks' ); ?></h1>

		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Errors stop an article being published for the first time. Warnings are shown to the author but never block anything. Saving to draft or pending is never blocked, whatever these are set to.', 'rendar-prepublish-checks' ); ?>
		</p>

		<form action="options.php" method="post">
			<?php settings_fields( 'rendar_pc' ); ?>

			<h2><?php esc_html_e( 'Post types', 'rendar-prepublish-checks' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose which public REST editor post types with custom-fields support receive advisory checks. Existing installations default to Posts only.', 'rendar-prepublish-checks' ); ?></p>
			<input type="hidden" name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[post_types][]" value="" />
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'rendar-prepublish-checks' ); ?></legend>
				<?php foreach ( rendar_pc_eligible_post_types() as $type => $label ) : ?>
					<label style="display:block">
						<input type="checkbox" name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, rendar_pc_get_post_types(), true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<h2><?php esc_html_e( 'Checks', 'rendar-prepublish-checks' ); ?></h2>

			<table class="widefat striped" style="max-width:60em">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Check', 'rendar-prepublish-checks' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Severity', 'rendar-prepublish-checks' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $checks as $id => $check ) : ?>
					<?php $current = rendar_pc_get_severity( $id, $check['severity'] ); ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $check['label'] ); ?></strong><br />
							<span class="description"><?php echo esc_html( $check['description'] ); ?></span>
						</td>
						<td>
							<label class="screen-reader-text" for="rendar-pc-<?php echo esc_attr( $id ); ?>">
								<?php echo esc_html( $check['label'] ); ?>
							</label>
							<select
								id="rendar-pc-<?php echo esc_attr( $id ); ?>"
								name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[severities][<?php echo esc_attr( $id ); ?>]"
							>
								<option value="off" <?php selected( $current, 'off' ); ?>>
									<?php esc_html_e( 'Off', 'rendar-prepublish-checks' ); ?>
								</option>
								<option value="warning" <?php selected( $current, 'warning' ); ?>>
									<?php esc_html_e( 'Warning', 'rendar-prepublish-checks' ); ?>
								</option>
								<option value="error" <?php selected( $current, 'error' ); ?>>
									<?php esc_html_e( 'Error — blocks publishing', 'rendar-prepublish-checks' ); ?>
								</option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Options', 'rendar-prepublish-checks' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="rendar-pc-min-width"><?php esc_html_e( 'Minimum featured image width', 'rendar-prepublish-checks' ); ?></label>
					</th>
					<td>
						<input
							type="number" min="0" max="10000" step="1" class="small-text"
							id="rendar-pc-min-width"
							name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[min_featured_width]"
							value="<?php echo esc_attr( (string) $settings['min_featured_width'] ); ?>"
						/> px
						<p class="description"><?php esc_html_e( 'Zero turns the check off entirely.', 'rendar-prepublish-checks' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="rendar-pc-ideal-width"><?php esc_html_e( 'Preferred featured image width', 'rendar-prepublish-checks' ); ?></label>
					</th>
					<td>
						<input
							type="number" min="0" max="10000" step="1" class="small-text"
							id="rendar-pc-ideal-width"
							name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[ideal_featured_width]"
							value="<?php echo esc_attr( (string) $settings['ideal_featured_width'] ); ?>"
						/> px
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Classic content', 'rendar-prepublish-checks' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox" value="1"
								name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[skip_classic_content]"
								<?php checked( ! empty( $settings['skip_classic_content'] ) ); ?>
							/>
							<?php esc_html_e( 'Skip the image checks on articles whose content is classic rather than blocks', 'rendar-prepublish-checks' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Nearly every article in the existing archive is classic content. With this off, opening and republishing an old article will report every image in it.', 'rendar-prepublish-checks' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 id="rendar-pc-notify"><?php esc_html_e( 'Email when an article does not go live', 'rendar-prepublish-checks' ); ?></h2>

			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Sent when a scheduled article fails its checks at its scheduled time and is moved back to pending, or when someone publishes or schedules from Quick Edit, Bulk Edit or the classic editor and the article is saved as pending instead. One email per failure.', 'rendar-prepublish-checks' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send the email', 'rendar-prepublish-checks' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox" value="1"
								name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[notify_enabled]"
								<?php checked( ! empty( $settings['notify_enabled'] ) ); ?>
							/>
							<?php esc_html_e( 'Email when an article that was meant to go live did not', 'rendar-prepublish-checks' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="rendar-pc-notify-recipients"><?php esc_html_e( 'Send it to', 'rendar-prepublish-checks' ); ?></label>
					</th>
					<td>
						<textarea
							id="rendar-pc-notify-recipients" rows="3" cols="46" class="large-text code" style="max-width:32em"
							name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[notify_recipients]"
						><?php echo esc_textarea( implode( "\n", (array) $settings['notify_recipients'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One address per line. Anything that is not a valid email address is dropped when you save.', 'rendar-prepublish-checks' ); ?></p>
						<p>
							<label>
								<input
									type="checkbox" value="1"
									name="<?php echo esc_attr( RENDAR_PC_OPTION ); ?>[notify_scheduler]"
									<?php checked( ! empty( $settings['notify_scheduler'] ) ); ?>
								/>
								<?php esc_html_e( 'Also email the person who scheduled or tried to publish the article', 'rendar-prepublish-checks' ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>

			<?php
			/**
			 * Lets another component add a clearly labelled section to this
			 * screen. The section's option must be registered in the
			 * `rendar_pc` settings group, so this form saves it.
			 *
			 * Used, for example, by a diagnostics mu-plugin for its cron-logging
			 * toggle. It owns its option, default and sanitising; this screen
			 * only offers the slot, so neither component requires the other.
			 *
			 * @since 0.1.0
			 */
			do_action( 'rendar_prepublish_checks_settings_sections' );
			?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
