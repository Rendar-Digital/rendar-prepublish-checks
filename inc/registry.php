<?php
/**
 * The check registry and the single evaluation entry point.
 *
 * Every check is run through rendar_pc_evaluate() and nowhere else. The
 * advisory REST endpoint, the publish gate and the scheduled re-check all call
 * it with a context built the same way, which is what makes "the panel agrees
 * with the gate" a structural property rather than a thing to keep testing.
 *
 * @package Rendar_Prepublish_Checks
 */

defined( 'ABSPATH' ) || exit;

const RENDAR_PC_STATUS_PASS        = 'pass';
const RENDAR_PC_STATUS_FAIL        = 'fail';
const RENDAR_PC_STATUS_NA          = 'not_applicable';
const RENDAR_PC_STATUS_UNAVAILABLE = 'unavailable';

/**
 * The four — and only four — result statuses a check may return (docs/API.md).
 *
 * @return string[]
 */
function rendar_pc_statuses() {
	return array(
		RENDAR_PC_STATUS_PASS,
		RENDAR_PC_STATUS_FAIL,
		RENDAR_PC_STATUS_NA,
		RENDAR_PC_STATUS_UNAVAILABLE,
	);
}

/**
 * Coerce a check callback's return value into a valid result, or to
 * unavailable when it is not one of the four documented shapes.
 *
 * docs/API.md promises every v1 result is an array of { status (one of the
 * four), message (string), offenders (optional list of arrays) }, and that a
 * callback returning anything else is treated as unavailable ("this check
 * did not return a result"), never a silent pass. This is where that promise is
 * ENFORCED rather than assumed. A third-party callback cannot weaken the gate
 * or crash the evaluator by returning the wrong shape:
 *
 *   - a non-array, or an unknown/ missing status  -> unavailable;
 *   - a missing or non-scalar message             -> unavailable;
 *   - offenders that are not a list, contain a non-array entry, or have an
 *     invalid key, label or edit_url              -> unavailable.
 *
 * None of these paths emits a PHP warning: a non-scalar message is rejected,
 * not cast to the literal "Array", and a scalar `offenders` never reaches
 * array_values() (which would TypeError).
 *
 * @param mixed $result Raw callback return value.
 * @return array A valid result array: status, message, offenders.
 */
function rendar_pc_normalize_result( $result ) {
	$fallback = rendar_pc_unavailable(
		__( 'This check did not return a valid result.', 'rendar-prepublish-checks' )
	);

	if ( ! is_array( $result ) ) {
		return $fallback;
	}

	if ( ! isset( $result['status'] ) || ! in_array( $result['status'], rendar_pc_statuses(), true ) ) {
		return $fallback;
	}

	if ( ! isset( $result['message'] ) || ! is_scalar( $result['message'] ) ) {
		return $fallback;
	}

	$offenders = array();

	if ( isset( $result['offenders'] ) ) {
		if ( ! is_array( $result['offenders'] ) ) {
			return $fallback;
		}

		foreach ( $result['offenders'] as $offender ) {
			if ( ! is_array( $offender )
				|| ! isset( $offender['key'] )
				|| ! is_string( $offender['key'] )
				|| '' === $offender['key']
				|| ( array_key_exists( 'label', $offender ) && ! is_string( $offender['label'] ) )
				|| ( array_key_exists( 'edit_url', $offender ) && ! is_string( $offender['edit_url'] ) )
			) {
				// The editor renders key, label and edit_url directly. Reject an
				// invalid entry rather than letting an extension pass an array or
				// object through to React as a child or href.
				return $fallback;
			}

			// label is optional in the public schema. Give it a useful scalar
			// fallback so every consumer receives the same safe shape.
			if ( ! isset( $offender['label'] ) ) {
				$offender['label'] = $offender['key'];
			}

			$offenders[] = $offender;
		}
	}

	return array(
		'status'    => $result['status'],
		'message'   => (string) $result['message'],
		'offenders' => array_values( $offenders ),
	);
}

/**
 * A passing result.
 *
 * @param string $message Human-readable summary.
 * @return array
 */
function rendar_pc_pass( $message ) {
	return array(
		'status'    => RENDAR_PC_STATUS_PASS,
		'message'   => $message,
		'offenders' => array(),
	);
}

/**
 * A failing result.
 *
 * @param string  $message   Human-readable summary.
 * @param array[] $offenders Things at fault. Each may carry id, key, label, edit_url.
 * @return array
 */
function rendar_pc_fail( $message, array $offenders = array() ) {
	return array(
		'status'    => RENDAR_PC_STATUS_FAIL,
		'message'   => $message,
		'offenders' => $offenders,
	);
}

/**
 * A result that does not apply to this post.
 *
 * Distinct from passing on purpose. A skipped check that renders as a tick is a
 * lie the author will act on — the panel says "skipped" and says why.
 *
 * @param string $message Reason the check does not apply.
 * @return array
 */
function rendar_pc_not_applicable( $message ) {
	return array(
		'status'    => RENDAR_PC_STATUS_NA,
		'message'   => $message,
		'offenders' => array(),
	);
}

/**
 * A result that could NOT be determined because a capability it needs is absent
 * — e.g. ACF is not installed, or WP_HTML_Tag_Processor is missing.
 *
 * Deliberately NOT a pass and NOT not-applicable. "Not applicable" says the
 * check does not apply to this post; "unavailable" says the check DOES apply but
 * the environment cannot answer it, so the author is told the truth instead of
 * being shown a green tick that means nothing. An unavailable image or field
 * check must never read as a silent pass.
 *
 * @param string $message Reason the check could not run.
 * @return array
 */
function rendar_pc_unavailable( $message ) {
	return array(
		'status'    => RENDAR_PC_STATUS_UNAVAILABLE,
		'message'   => $message,
		'offenders' => array(),
	);
}

/**
 * The registered checks.
 *
 * Filterable so a future check is an addition rather than a refactor. A
 * definition is:
 *
 *   id          string   Stable identifier. Also the settings key.
 *   label       string   Short name for the panel.
 *   description string   One line explaining what the check wants.
 *   severity    string   Default severity; the stored setting wins.
 *   applies_to  string[] Post types, or ['*'] for all configured types.
 *   requirements array   Version 1 applicability prerequisites (docs/API.md).
 *   callback    callable fn( Rendar_PC_Context ): array
 *
 * @return array[] Keyed by check ID.
 */
function rendar_pc_get_checks() {
	$checks = rendar_pc_builtin_checks();

	/**
	 * Filter the registered pre-publish checks.
	 *
	 * @param array[] $checks Keyed by check ID.
	 */
	$checks = apply_filters( 'rendar_prepublish_checks_definitions', $checks );

	rendar_pc_reset_invalid_definitions();

	$valid = array();

	foreach ( (array) $checks as $id => $check ) {
		$raw_id = is_string( $id ) ? $id : '';
		$id     = sanitize_key( $raw_id );

		// A malformed descriptor is logged and skipped, never allowed to
		// register a broken check or overwrite a good one. None of these paths
		// emits a PHP warning: an array where a string belongs is rejected, not
		// cast to the literal "Array".
		if ( '' === $id ) {
			rendar_pc_record_invalid_definition( (string) $raw_id, 'empty or non-string id' );
			continue;
		}

		if ( ! is_array( $check ) ) {
			rendar_pc_record_invalid_definition( $id, 'definition is not an array' );
			continue;
		}

		if ( empty( $check['callback'] ) || ! is_callable( $check['callback'] ) ) {
			rendar_pc_record_invalid_definition( $id, 'missing or non-callable callback' );
			continue;
		}

		// Two distinct ids that collapse to the same sanitized id (e.g. "Foo"
		// and "foo") are a duplicate. Keep the first; reject the rest rather
		// than silently overwriting an already-registered check.
		if ( isset( $valid[ $id ] ) ) {
			rendar_pc_record_invalid_definition( $id, 'duplicate id (sanitizes to an already-registered check)' );
			continue;
		}

		// Field-level coercions: the check still registers, but with a safe
		// fallback, and the coercion is logged so a malformed field is not
		// invisible. None of these casts a non-scalar (which would warn).
		$severity = RENDAR_PC_SEVERITY_WARNING;
		if ( isset( $check['severity'] ) ) {
			if ( is_string( $check['severity'] ) && in_array( $check['severity'], rendar_pc_severities(), true ) ) {
				$severity = $check['severity'];
			} else {
				rendar_pc_record_invalid_definition( $id, 'invalid severity ' . ( is_scalar( $check['severity'] ) ? '"' . (string) $check['severity'] . '"' : gettype( $check['severity'] ) ) . ' (defaulted to warning)' );
			}
		}

		$label = $id;
		if ( isset( $check['label'] ) ) {
			if ( is_scalar( $check['label'] ) ) {
				$label = (string) $check['label'];
			} else {
				rendar_pc_record_invalid_definition( $id, 'non-scalar label (defaulted to the id)' );
			}
		}

		$description = '';
		if ( isset( $check['description'] ) ) {
			if ( is_scalar( $check['description'] ) ) {
				$description = (string) $check['description'];
			} else {
				rendar_pc_record_invalid_definition( $id, 'non-scalar description (defaulted to empty)' );
			}
		}

		if ( array_key_exists( 'applies_to', $check ) && ( ! is_array( $check['applies_to'] ) || ! $check['applies_to']
			|| count( $check['applies_to'] ) !== count( array_filter( $check['applies_to'], function ( $type ) { return is_string( $type ) && ( '*' === $type || ( '' !== $type && sanitize_key( $type ) === $type ) ); } ) ) ) ) {
			rendar_pc_record_invalid_definition( $id, 'invalid applies_to' );
			continue;
		}
		$requirements = array_key_exists( 'requirements', $check ) ? $check['requirements'] : array( 'version' => 1 );
		if ( ! rendar_pc_valid_requirements( $requirements ) ) {
			rendar_pc_record_invalid_definition( $id, 'invalid requirements descriptor (version 1 only)' );
			continue;
		}

		$valid[ $id ] = array(
			'id'          => $id,
			'label'       => $label,
			'description' => $description,
			'severity'    => $severity,
			'applies_to'  => rendar_pc_sanitize_applies_to( isset( $check['applies_to'] ) ? $check['applies_to'] : array( '*' ) ),
			'callback'    => $check['callback'],
			'requirements' => $requirements,
		);
	}

	return $valid;
}

/**
 * Normalise a descriptor's applies_to to a list of non-empty strings.
 *
 * The registry rejects malformed explicit values before reaching this helper.
 * Omission alone defaults to all configured types (['*']).
 *
 * @param mixed $applies_to Raw applies_to value.
 * @return string[]
 */
function rendar_pc_sanitize_applies_to( $applies_to ) {
	if ( ! is_array( $applies_to ) ) {
		return array( '*' );
	}

	$clean = array();
	foreach ( $applies_to as $entry ) {
		if ( is_string( $entry ) && '' !== $entry ) {
			$clean[] = $entry;
		}
	}

	return $clean ? array_values( array_unique( $clean ) ) : array( '*' );
}

/**
 * Strict versioned applicability schema. Unknown keys and malformed lists reject
 * the whole definition rather than silently weakening a future publish gate.
 */
function rendar_pc_valid_requirements( $requirements ) {
	if ( ! is_array( $requirements ) || ! isset( $requirements['version'] ) || 1 !== $requirements['version'] ) {
		return false;
	}
	foreach ( $requirements as $key => $value ) {
		if ( ! in_array( $key, array( 'version', 'taxonomies', 'supports', 'dependencies' ), true ) ) {
			return false;
		}
		if ( 'version' === $key ) {
			continue;
		}
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
			return false;
		}
		$allowed = array(
			'taxonomies'   => array( 'category', 'post_tag' ),
			'supports'     => array( 'editor', 'thumbnail' ),
			'dependencies' => array( 'acf', 'html_tag_processor' ),
		);
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) || ! in_array( $entry, $allowed[ $key ], true ) ) {
				return false;
			}
		}
		// Compare only validated strings; SORT_REGULAR on arbitrary objects can
		// invoke their comparisons before the schema has rejected them.
		if ( count( $value ) !== count( array_unique( $value, SORT_STRING ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Return a non-pass result when declared applicability or data access is absent.
 * Registration is not assignment: an attached but empty taxonomy runs its check.
 */
function rendar_pc_requirements_result( array $check, Rendar_PC_Context $context ) {
	$requirements = $check['requirements'];
	foreach ( $requirements['taxonomies'] ?? array() as $taxonomy ) {
		if ( ! is_object_in_taxonomy( $context->post_type, $taxonomy ) ) {
			return rendar_pc_not_applicable( sprintf( __( 'The %s taxonomy is not registered for this post type.', 'rendar-prepublish-checks' ), $taxonomy ) );
		}
	}
	foreach ( $requirements['supports'] ?? array() as $support ) {
		if ( ! post_type_supports( $context->post_type, $support ) ) {
			return rendar_pc_not_applicable( sprintf( __( 'This post type does not support %s.', 'rendar-prepublish-checks' ), $support ) );
		}
	}
	foreach ( $requirements['dependencies'] ?? array() as $dependency ) {
		if ( 'acf' === $dependency && ! $context->acf_available ) {
			return rendar_pc_unavailable( __( 'ACF is not active, so required custom fields could not be checked.', 'rendar-prepublish-checks' ) );
		}
		if ( 'html_tag_processor' === $dependency && ! class_exists( 'WP_HTML_Tag_Processor' )
			&& '' !== trim( $context->content ) && ! $context->skips_content_checks() ) {
			return rendar_pc_unavailable( __( 'The WordPress HTML tag processor is unavailable; content images could not be checked.', 'rendar-prepublish-checks' ) );
		}
	}
	return null;
}

/**
 * Rejected-descriptor log, for observability and tests. Reset at the start of
 * every rendar_pc_get_checks() pass so it reflects the current registry, never
 * emitting a PHP warning or notice.
 *
 * @param string|null $reset Internal: 'reset' clears; 'get' returns; else records.
 * @param string      $id     Check id.
 * @param string      $reason Why it was rejected.
 * @return array[] The current log when reading.
 */
function rendar_pc_invalid_definition_log( $reset = 'get', $id = '', $reason = '' ) {
	static $log = array();

	if ( 'reset' === $reset ) {
		$log = array();
		return $log;
	}

	if ( 'record' === $reset ) {
		$log[] = array( 'id' => $id, 'reason' => $reason );
		return $log;
	}

	return $log;
}

/** Clear the rejected-descriptor log. */
function rendar_pc_reset_invalid_definitions() {
	rendar_pc_invalid_definition_log( 'reset' );
}

/** Record a rejected descriptor. */
function rendar_pc_record_invalid_definition( $id, $reason ) {
	rendar_pc_invalid_definition_log( 'record', (string) $id, (string) $reason );
}

/** The descriptors rejected during the last rendar_pc_get_checks() pass. */
function rendar_pc_invalid_definitions() {
	return rendar_pc_invalid_definition_log( 'get' );
}

/**
 * Does a check apply to this post type?
 *
 * @param array  $check     Check definition.
 * @param string $post_type Post type.
 * @return bool
 */
function rendar_pc_check_applies( array $check, $post_type ) {
	if ( in_array( '*', $check['applies_to'], true ) ) {
		return true;
	}

	return in_array( $post_type, $check['applies_to'], true );
}

/**
 * Run every applicable check against a context.
 *
 * @param Rendar_PC_Context $context Context to evaluate.
 * @return array Evaluation report.
 */
function rendar_pc_evaluate( Rendar_PC_Context $context ) {
	$report = array(
		'post_id'          => $context->post_id,
		'target_status'    => $context->target_status,
		'in_scope'         => in_array( $context->post_type, rendar_pc_get_post_types(), true ),
		'is_publishing'    => $context->is_publishing_status(),
		'was_published'    => $context->was_published,
		'predates_checks'  => $context->predates_checks,
		'has_real_blocks'  => $context->has_real_blocks,
		'checks'           => array(),
		'failing_errors'   => array(),
		'failing_warnings' => array(),
		'unavailable_errors' => array(),
		'blocking_ids'    => array(),
		'override'         => null,
		'override_covers'  => array(),
		'override_missing' => array(),
		'gated'            => false,
		'blocking'         => false,
	);

	if ( ! $report['in_scope'] ) {
		return $report;
	}

	$checks = rendar_pc_get_checks();
	foreach ( $checks as $id => $check ) {
		if ( ! rendar_pc_check_applies( $check, $context->post_type ) ) {
			continue;
		}

		$severity = rendar_pc_get_severity( $id, $check['severity'] );

		if ( RENDAR_PC_SEVERITY_OFF === $severity ) {
			continue;
		}

		// A malformed callback result means the check could not run; never
		// classify it as not applicable (which would silently permit publishing).
		$requirement_result = rendar_pc_requirements_result( $check, $context );
		$result = $requirement_result ? $requirement_result : rendar_pc_normalize_result( call_user_func( $check['callback'], $context ) );

		$entry = array(
			'id'          => $id,
			'label'       => $check['label'],
			'description' => $check['description'],
			'severity'    => $severity,
			'status'      => $result['status'],
			'message'     => $result['message'],
			'offenders'   => $result['offenders'],
		);

		$report['checks'][] = $entry;

		if ( RENDAR_PC_STATUS_UNAVAILABLE === $entry['status'] ) {
			if ( RENDAR_PC_SEVERITY_ERROR === $severity ) {
				$report['unavailable_errors'][] = $id;
			}
			continue;
		}
		if ( RENDAR_PC_STATUS_FAIL !== $entry['status'] ) {
			continue;
		}

		if ( RENDAR_PC_SEVERITY_ERROR === $severity ) {
			$report['failing_errors'][] = $id;
		} else {
			$report['failing_warnings'][] = $id;
		}
	}

	// A stored error policy whose definition vanished (or failed validation)
	// cannot be silently discarded. Only an explicit error setting qualifies:
	// unknown stale warning/off keys do not acquire an invented default.
	$severities = rendar_pc_get_setting( 'severities', array() );
	foreach ( ( is_array( $severities ) ? $severities : array() ) as $id => $severity ) {
		if ( RENDAR_PC_SEVERITY_ERROR !== $severity || ! is_string( $id )
			|| '' === $id || isset( $checks[ $id ] ) ) {
			continue;
		}
		$report['checks'][] = array(
			'id' => $id, 'label' => $id, 'description' => '',
			'severity' => RENDAR_PC_SEVERITY_ERROR,
			'status' => RENDAR_PC_STATUS_UNAVAILABLE,
			'message' => __( 'Configured error check definition is missing or invalid.', 'rendar-prepublish-checks' ),
			'offenders' => array(),
		);
		$report['unavailable_errors'][] = $id;
	}

	$report['blocking_ids'] = array_values( array_unique( array_merge( $report['failing_errors'], $report['unavailable_errors'] ) ) );
	$report = rendar_pc_apply_gate_decision( $report, $context );

	return $report;
}

/**
 * Decide whether this evaluation blocks the save.
 *
 * Three rules, in order:
 *
 * 1. Only a publishing status is ever gated. Draft and pending saves are the
 *    author's workflow and stay frictionless — that is the whole
 *    reason the checks are advisory in the document panel.
 * 2. A post that has already been published is never blocked. Otherwise
 *    switching the plugin on would make routine edits to the existing archive
 *    impossible, since those articles were written under no such rules.
 * 3. Neither is a post written before the checks existed. Rule 2 covers the
 *    published archive; this covers the drafts and pending articles sitting
 *    behind it, which were written to the same old standard and are nobody's
 *    fault. Keyed on age rather than on classic content, because content shape
 *    can be acquired by accident — has_blocks( '' ) is false, so every new
 *    article would otherwise be exempt until someone typed into it — and a
 *    creation date cannot.
 * 4. Otherwise, any failing or unavailable error-severity check blocks unless
 *    a valid override covers every blocking ID.
 *
 * @param array                 $report  Report so far.
 * @param Rendar_PC_Context $context Context.
 * @return array
 */
function rendar_pc_apply_gate_decision( array $report, Rendar_PC_Context $context ) {
	$report['gated'] = $context->is_publishing_status()
		&& ! $context->was_published
		&& ! $context->predates_checks;

	if ( ! $report['gated'] || ! $report['blocking_ids'] ) {
		return $report;
	}

	$override = $context->override;

	if ( $override ) {
		$report['override'] = array(
			'reason' => $override['reason'],
			'checks' => $override['checks'],
		);

		// An override authorizes the failures it named and nothing else. Without
		// this, a token granted for a missing tag would silently authorize a
		// later publish that fails a different check.
		$missing = array_values( array_diff( $report['blocking_ids'], $override['checks'] ) );

		$report['override_covers']  = array_values( array_intersect( $report['blocking_ids'], $override['checks'] ) );
		$report['override_missing'] = $missing;

		if ( ! $missing ) {
			return $report;
		}
	}

	$report['blocking'] = true;

	return $report;
}

/**
 * Human-readable summary of blocking checks (failed or unavailable), for the gate's WP_Error.
 *
 * @param array $report Evaluation report.
 * @return string[]
 */
function rendar_pc_failing_messages( array $report ) {
	$messages = array();
	$failing  = array_flip( $report['blocking'] && $report['override_missing'] ? $report['override_missing'] : $report['blocking_ids'] );

	foreach ( $report['checks'] as $check ) {
		if ( isset( $failing[ $check['id'] ] ) ) {
			$messages[] = sprintf( '%s: %s', $check['label'], $check['message'] );
		}
	}

	return $messages;
}
