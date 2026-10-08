<?php
/**
 * Editor assets.
 *
 * No build step: the panel is a few hundred
 * lines of wp.element.createElement, and a toolchain whose output has to be
 * committed and kept in sync is a poor trade for JSX here.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue the editor panel.
 *
 * Registered on two hooks on purpose — see rendar_pc_register_editor_assets()
 * for why. wp_enqueue_script() is idempotent, so being called twice costs
 * nothing.
 *
 * @return void
 */
function rendar_pc_enqueue_editor_assets() {
	if ( wp_script_is( 'rendar-pc-editor', 'enqueued' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && $screen->post_type && ! in_array( $screen->post_type, rendar_pc_get_post_types(), true ) ) {
		return;
	}

	wp_enqueue_script(
		'rendar-pc-editor',
		RENDAR_PC_URL . 'assets/js/editor.js',
		array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-api-fetch', 'wp-i18n' ),
		RENDAR_PC_VERSION,
		true
	);

	wp_localize_script(
		'rendar-pc-editor',
		'rendarPrepublish',
		array(
			'restPath'          => '/' . RENDAR_PC_REST_NS . '/evaluate',
			'issuePath'         => '/' . RENDAR_PC_REST_NS . '/issue/',
			'postTypes'         => rendar_pc_get_post_types(),
			'decorativeMetaKey' => RENDAR_PC_META_DECORATIVE,
			'overrideMetaKey'   => RENDAR_PC_META_OVERRIDE,
			// Enforcement capability flag. When off (advisory-only) the /issue route is
			// not registered, so the editor must not poll it after a save -- that
			// would be pure 404 traffic. editor.js gates its issue watcher on this.
			'enforcement'       => rendar_pc_enforcement_enabled(),
		)
	);

	wp_enqueue_style(
		'rendar-pc-editor',
		RENDAR_PC_URL . 'assets/css/editor.css',
		array(),
		RENDAR_PC_VERSION
	);

	wp_set_script_translations( 'rendar-pc-editor', 'rendar-prepublish-checks' );
}

/**
 * Enqueue early, on the block editor screen only.
 *
 * This exists to put the panel at the top of the document sidebar, where an
 * author sees it before anything else. There is no ordering API for PluginDocumentSettingPanel: fills
 * render in the order their plugins called registerPlugin, which is the order
 * their scripts execute, which is the order they were enqueued. So position in
 * that sidebar is decided entirely by when this file runs.
 *
 * `enqueue_block_editor_assets` is the obvious hook and it is too late.
 * Measured on a real site: `admin_enqueue_scripts` had already queued 66
 * scripts — Yoast's among them — before `enqueue_block_editor_assets` fired at
 * all. No priority on the later hook can beat a callback on the earlier one, so
 * the fix is the hook, not the number.
 *
 * The screen guard matters: `admin_enqueue_scripts` runs on every admin page,
 * and this script has no business on any of them but the block editor.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function rendar_pc_enqueue_editor_assets_early( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
		return;
	}

	rendar_pc_enqueue_editor_assets();
}

/**
 * Register both hooks.
 *
 * The early one wins the ordering; the late one is the safety net, because the
 * early path depends on the screen already knowing it is a block editor. If
 * that ever stops being true the panel still loads — just further down the
 * sidebar, which is a cosmetic regression rather than a missing feature.
 *
 * @return void
 */
function rendar_pc_register_editor_assets() {
	add_action( 'admin_enqueue_scripts', 'rendar_pc_enqueue_editor_assets_early', 0 );
	add_action( 'enqueue_block_editor_assets', 'rendar_pc_enqueue_editor_assets', 0 );
}
rendar_pc_register_editor_assets();
