<?php
/** Watermark behavior in isolation; plugin boot still leaves enforcement off. */
require __DIR__ . '/stubs.php';
class WP_Post { public $ID; public function __construct( $id ) { $this->ID = $id; } }
$GLOBALS['reverted_test_ids'] = array();
function rendar_pc_was_reverted( $id ) { return in_array( $id, $GLOBALS['reverted_test_ids'], true ); }
require dirname( __DIR__ ) . '/rendar-prepublish-checks.php';
fire_hook( 'plugins_loaded' );
check( ! rendar_pc_enforcement_enabled(), 'watermark writer remains off at boot' );
check( ! in_array( 'rendar_pc_record_first_publish', hook_callbacks( 'transition_post_status' ), true ), 'watermark hook is not registered' );
$GLOBALS['reverted_test_ids'][] = 140;
rendar_pc_record_first_publish( 'publish', 'future', new WP_Post( 140 ) );
check( '' === get_post_meta( 140, RENDAR_PC_META_FIRST_PUBLISHED, true ), 'failed scheduled publish cannot acquire watermark' );
rendar_pc_record_first_publish( 'pending', 'draft', new WP_Post( 141 ) );
check( '' === get_post_meta( 141, RENDAR_PC_META_FIRST_PUBLISHED, true ), 'pending save cannot acquire watermark' );
rendar_pc_record_first_publish( 'publish', 'draft', new WP_Post( 142 ) );
$first = get_post_meta( 142, RENDAR_PC_META_FIRST_PUBLISHED, true );
check( '' !== $first, 'first publish acquires watermark' );
// Advance the controllable clock so a bypassed existing-watermark guard cannot
// pass merely because both writes happen to use the same stub timestamp.
$GLOBALS['current_time'] = '2026-01-02 00:00:00';
check( $first !== current_time( 'mysql', true ), 'republish clock differs from existing watermark' );
rendar_pc_record_first_publish( 'publish', 'pending', new WP_Post( 142 ) );
check( $first === get_post_meta( 142, RENDAR_PC_META_FIRST_PUBLISHED, true ), 'later publish cannot replace first watermark' );
echo "first-published dormant contract: OK\n";
