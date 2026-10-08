<?php
/**
 * Advisory-only mode (enforcement off): the evaluator and its read-only
 * REST evaluate route, the editor panel and the decorative meta load; the gate,
 * scheduler, issue store, notifier, admin issue queue, override meta, override
 * write paths and the /issue route do NOT. No enforcement hook is registered,
 * and the editor-facing verdict degrades (no publish block, no override).
 */
require __DIR__ . '/stubs.php';

// RENDAR_PC_ENFORCEMENT left undefined => enforcement off.
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';

// Boot is deferred to plugins_loaded; before it fires, nothing is required.
check( function_exists( 'rendar_pc_boot' ), 'boot deferred (rendar_pc_boot defined)' );
check( ! function_exists( 'rendar_pc_evaluate' ), 'advisory core not required before plugins_loaded' );
fire_hook( 'plugins_loaded' );

check( ! rendar_pc_enforcement_enabled(), 'enforcement off by default' );

// Advisory core loaded.
check( function_exists( 'rendar_pc_evaluate' ), 'evaluator loaded' );
check( function_exists( 'rendar_pc_register_routes' ), 'rest evaluate code loaded' );
check( function_exists( 'rendar_pc_register_meta' ), 'meta code loaded' );
check( function_exists( 'rendar_pc_register_editor_assets' ), 'editor assets loaded' );

// Enforcement files NOT loaded at all (their functions are simply undefined).
foreach ( array(
	'rendar_pc_register_gate',          // gate.php
	'rendar_pc_insert_backstop',        // gate.php
	'rendar_pc_deferred_enter',         // gate.php
	'rendar_pc_precheck_future_post',   // scheduled.php
	'rendar_pc_recheck_scheduled',      // scheduled.php
	'rendar_pc_issue_write_enter',      // issues.php
	'rendar_pc_record_scheduler',       // issues.php
	'rendar_pc_notify_issue_finalized', // notify.php
	'rendar_pc_add_issues_page',        // admin-issues.php
) as $fn ) {
	check( ! function_exists( $fn ), "enforcement function not loaded: $fn" );
}

// The decisive "no gate hooks registered" assertion: not one enforcement hook
// is attached to any of the status-write hooks the gate/scheduler/issue store
// use. record_first_publish is defined (meta.php) but must NOT be hooked while advisory-only.
check( empty( $GLOBALS['hooks']['wp_insert_post_data'] ), 'no wp_insert_post_data gate hooks' );
check( empty( $GLOBALS['hooks']['wp_after_insert_post'] ), 'no wp_after_insert_post hooks' );
check( empty( $GLOBALS['hooks']['publish_future_post'] ), 'no publish_future_post hooks' );
check( empty( $GLOBALS['hooks']['transition_post_status'] ), 'no transition_post_status hooks (first-publish watermark gated off)' );

// Fire the registration hooks that DID register.
fire_hook( 'init' );
fire_hook( 'rest_api_init' );

// Only the decorative meta is registered; the override meta is not.
check( in_array( RENDAR_PC_META_DECORATIVE, registered_meta_keys(), true ), 'decorative meta registered' );
check( ! in_array( RENDAR_PC_META_OVERRIDE, registered_meta_keys(), true ), 'override meta NOT registered while advisory-only' );

// Only the evaluate route is registered; the issue route is not.
check( in_array( '/evaluate', registered_routes(), true ), 'evaluate route registered' );
check( ! in_array( '/issue/(?P<post_id>\\d+)', registered_routes(), true ), 'issue route NOT registered while advisory-only' );

// Editor-facing verdict degrades: even when the pure evaluator would block and
// the user can manage_options, the shaped report shows no block and no override.
$GLOBALS['allowed'] = true;
$report = array(
	'in_scope' => true, 'gated' => true, 'blocking' => true, 'is_publishing' => true,
	'was_published' => false, 'predates_checks' => false, 'has_real_blocks' => true,
	'failing_errors' => array( 'featured_image' ), 'failing_warnings' => array(), 'checks' => array(),
);
$context = ( new ReflectionClass( 'Rendar_PC_Context' ) )->newInstanceWithoutConstructor();

// Non-vacuous override-log suppression: a real post id with a stored log, so
// array() here proves enforcement-off SUPPRESSION, not merely a null post id
// (which would return array() regardless). The enforcement-on test asserts the
// same post surfaces the stored log, closing the loop.
$context->post_id = 4242;
update_post_meta( 4242, RENDAR_PC_META_OVERRIDE_LOG, array( array( 'by' => 7, 'at' => '2026-01-01 00:00:00' ) ) );
check( array() !== rendar_pc_get_override_log( 4242 ), 'fixture: post 4242 has a stored override log' );

$shaped  = rendar_pc_shape_report( $report, $context );
check( false === $shaped['would_block'], 'would_block forced false while advisory-only' );
check( false === $shaped['can_override'], 'can_override forced false while advisory-only' );
check( array() === $shaped['override_log'], 'override_log suppressed while advisory-only despite a stored log' );
// The advisory content is intact: check results and failing list still present.
check( array( 'featured_image' ) === $shaped['failing_errors'], 'failing errors still reported (advisory)' );

echo "\nadvisory-only: OK\n";
