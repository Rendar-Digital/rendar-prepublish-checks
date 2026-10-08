/**
 * Pre-publish checks panel.
 *
 * Two surfaces, one component: a document panel visible the whole time the
 * author is writing, and the pre-publish panel that appears when they press
 * Publish or Submit for Review.
 *
 * The component computes nothing. It sends the editor's current state to
 * /rendar-prepublish-checks/v1/evaluate and renders the answer. That is the point:
 * a second implementation of the rules in JavaScript would drift from the PHP
 * gate, and the first time it did, an author would be told their article was
 * fine and then refused at publish.
 */
( function ( wp ) {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel; // moved here from wp.editPost in WP 6.6
	var PluginPrePublishPanel = wp.editor.PluginPrePublishPanel;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useEntityProp = wp.coreData.useEntityProp;
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var TextareaControl = wp.components.TextareaControl;
	var Notice = wp.components.Notice;

	var settings = window.rendarPrepublish || {};
	var DEBOUNCE_MS = 4000;
	var ISSUE_PATH = settings.issuePath || '/rendar-prepublish-checks/v1/issue/';
	// Enforcement capability. With enforcement off (advisory-only) the /issue route is
	// not registered server-side, so the issue watcher must not request it.
	var ENFORCEMENT = !! settings.enforcement;

	/**
	 * Latest-state, single-flight request scheduler.
	 *
	 * This owns transport cadence only. It knows nothing about checks or report
	 * shape; the PHP endpoint remains the sole evaluator. Dependencies are
	 * injected so the real scheduler can be exercised with a deterministic clock.
	 *
	 * @param {Object} options Scheduler dependencies and callbacks.
	 * @return {Object} Scheduler controls.
	 */
	function createEvaluationScheduler( options ) {
		var timer = null;
		var active = null;
		var lastSuccessfulKey = null;
		var failedKey = null;
		var dueAt = 0;
		var disposed = false;

		function payloadKey( payload ) {
			return JSON.stringify( payload );
		}

		function clearTimer() {
			if ( null === timer ) {
				return;
			}

			options.clearTimer( timer );
			timer = null;
		}

		function current() {
			var payload = options.buildPayload();

			return { payload: payload, key: payloadKey( payload ) };
		}

		function idle() {
			if ( options.onIdle ) {
				options.onIdle();
			}
		}

		function arm() {
			var wait;

			clearTimer();

			if ( disposed || active ) {
				return;
			}

			wait = Math.max( 0, dueAt - options.now() );
			timer = options.setTimer( run, wait );
		}

		function settle() {
			var latest;

			active = null;

			if ( disposed ) {
				return;
			}

			latest = current();

			if ( latest.key === lastSuccessfulKey || latest.key === failedKey ) {
				clearTimer();
				idle();
				return;
			}

			arm();
		}

		function succeed( request, response ) {
			var latest;

			if ( disposed ) {
				active = null;
				return;
			}

			latest = current();

			if ( latest.key === request.key ) {
				lastSuccessfulKey = request.key;
				failedKey = null;
				options.onSuccess( response );
			}

			settle();
		}

		function fail( request, error ) {
			var latest;

			if ( disposed ) {
				active = null;
				return;
			}

			latest = current();

			if ( latest.key === request.key ) {
				failedKey = request.key;
				options.onError( error );
			}

			settle();
		}

		function run() {
			var request;
			var sent;

			timer = null;

			if ( disposed || active ) {
				return;
			}

			request = current();

			if ( request.key === lastSuccessfulKey || request.key === failedKey ) {
				idle();
				return;
			}

			active = request;
			options.onStart();

			try {
				sent = options.send( request.payload );
			} catch ( error ) {
				fail( request, error );
				return;
			}

			Promise.resolve( sent ).then(
				function ( response ) {
					succeed( request, response );
				},
				function ( error ) {
					fail( request, error );
				}
			);
		}

		return {
			schedule: function ( immediate ) {
				if ( disposed ) {
					return;
				}

				// An explicit state/publish event is also the retry boundary for a
				// current request that failed. Settling alone never creates a loop.
				failedKey = null;
				dueAt = options.now() + ( immediate ? 0 : options.delay );
				arm();
			},
			dispose: function () {
				disposed = true;
				clearTimer();
			},
		};
	}

	/**
	 * The one-line message for a recorded publishing issue.
	 *
	 * @param {Object} response /issue response.
	 * @return {string} Message.
	 */
	function issueMessage( response ) {
		var issue = response.issue;
		var lead;

		if ( 'cron' === issue.source ) {
			lead = sprintf(
				/* translators: %s: date and time. */
				__( 'This article was scheduled for %s but failed its checks at that time, so it was not published and is now Pending.', 'rendar-prepublish-checks' ),
				issue.scheduled_display || issue.time_display
			);
		} else if ( 'future' === issue.intended_status ) {
			lead = __( 'This article was NOT scheduled. It failed its checks when it was saved, so it has been saved as Pending instead.', 'rendar-prepublish-checks' );
		} else {
			lead = __( 'This article was NOT published. It failed its checks when it was saved, so it has been saved as Pending instead.', 'rendar-prepublish-checks' );
		}

		return ( issue.messages && issue.messages.length ) ? lead + ' ' + issue.messages.join( ' ' ) : lead;
	}

	/**
	 * Read the post's recorded publishing issue back after a save.
	 *
	 * A save-time demotion happens in the block editor's meta-box follow-up
	 * POST, whose response the editor never renders, and the editor never
	 * re-reads the post afterwards — so without this it goes on saying
	 * "Scheduled" about a post the server has put back to pending. After every
	 * save (the REST save and the meta-box follow-up) this asks the server what
	 * actually happened: if the stored status differs from what the editor
	 * believes, the post is re-fetched into the editor's store so the status it
	 * shows is the real one, and a new save-time demotion raises an editor
	 * notice. Pure logic with injected dependencies, so it is testable in Node.
	 *
	 * @param {Object} options Dependencies.
	 * @return {Object} Controls.
	 */
	function createIssueWatcher( options ) {
		var inFlight = false;
		var again = null;
		var notified = {};

		function check( reason ) {
			if ( inFlight ) {
				// A save finished while a read was running: one more read, and a
				// "saved" reason wins over "mount".
				again = 'saved' === reason || 'saved' === again ? 'saved' : reason;
				return Promise.resolve();
			}

			inFlight = true;

			return Promise.resolve( options.fetchIssue() )
				.then( function ( response ) {
					var pending = [];

					if ( ! response ) {
						return;
					}

					options.onIssue( response );

					if ( response.post_status && response.post_status !== options.getSavedStatus() ) {
						pending.push( options.refreshPost() );
					}

					if (
						'saved' === reason &&
						response.active &&
						response.issue &&
						'backstop' === response.issue.source &&
						! notified[ response.issue.time ]
					) {
						notified[ response.issue.time ] = true;
						options.notify( issueMessage( response ) );
					}

					return Promise.all( pending );
				} )
				.catch( function () {
					// Advisory read. A failure here must never break the editor.
				} )
				.then( function () {
					var next = again;

					inFlight = false;
					again = null;

					if ( next ) {
						return check( next );
					}
				} );
		}

		return { check: check };
	}

	// Deterministic Node tests pre-create this object. Normal WordPress output
	// does not, so the implementation detail is not exposed in production.
	if ( window.rendarPrepublishTestHooks && 'object' === typeof window.rendarPrepublishTestHooks ) {
		window.rendarPrepublishTestHooks.createEvaluationScheduler = createEvaluationScheduler;
		window.rendarPrepublishTestHooks.createIssueWatcher = createIssueWatcher;
		window.rendarPrepublishTestHooks.issueMessage = issueMessage;
	}

	/**
	 * Wire createIssueWatcher() to the editor stores.
	 *
	 * @param {number} postId   Post ID.
	 * @param {string} postType Post type.
	 * @return {Object|null} Last /issue response.
	 */
	function usePublishingIssue( postId, postType ) {
		var saving = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var editPost = select( 'core/edit-post' );

			return {
				post: editor.isSavingPost() && ! editor.isAutosavingPost(),
				metaBoxes: editPost && 'function' === typeof editPost.isSavingMetaBoxes ? editPost.isSavingMetaBoxes() : false,
			};
		}, [] );

		var issueState = useState( null );
		var issue = issueState[ 0 ];
		var setIssue = issueState[ 1 ];

		var watcher = useRef( null );
		var previous = useRef( { post: false, metaBoxes: false } );

		if ( ! watcher.current ) {
			watcher.current = createIssueWatcher( {
				fetchIssue: function () {
					if ( ! ENFORCEMENT ) {
						return null;
					}

					var id = wp.data.select( 'core/editor' ).getCurrentPostId();

					return id ? apiFetch( { path: ISSUE_PATH + id } ) : null;
				},
				getSavedStatus: function () {
					return wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'status' );
				},
				refreshPost: function () {
					var editor = wp.data.select( 'core/editor' );
					var type = editor.getCurrentPostType();
					var id = editor.getCurrentPostId();
					var typeObject = wp.data.select( 'core' ).getPostType( type );
					var base = ( typeObject && typeObject.rest_base ) || 'posts';

					return apiFetch( { path: '/wp/v2/' + base + '/' + id + '?context=edit' } ).then( function ( record ) {
						wp.data.dispatch( 'core' ).receiveEntityRecords( 'postType', type, record );
					} );
				},
				notify: function ( message ) {
					var notices = wp.data.dispatch( 'core/notices' );

					// Core's own "Post scheduled." / "Post published." snackbar
					// (id editor-save) describes the REST save, which the server
					// has since undone. Leaving it up beside this notice would
					// contradict it.
					notices.removeNotice( 'editor-save' );
					notices.createErrorNotice( message, {
						id: 'rendar-pc-backstop',
						isDismissible: true,
					} );
				},
				onIssue: setIssue,
			} );
		}

		useEffect(
			function () {
				if ( postId && postType && settings.postTypes.indexOf( postType ) !== -1 ) {
					watcher.current.check( 'mount' );
				}
			},
			[ postId, postType ]
		);

		useEffect(
			function () {
				var finished = ( previous.current.post && ! saving.post ) || ( previous.current.metaBoxes && ! saving.metaBoxes );

				previous.current = { post: saving.post, metaBoxes: saving.metaBoxes };

				if ( finished && postId && settings.postTypes.indexOf( postType ) !== -1 ) {
					watcher.current.check( 'saved' );
				}
			},
			[ saving.post, saving.metaBoxes ]
		);

		return issue;
	}

	/**
	 * Evaluate the editor's current state, debounced and single-flight.
	 *
	 * The panel always asks "what happens when this is published", regardless of
	 * the status the author is actually saving to. An author saving to pending
	 * wants to know what the publisher will hit at publish time — telling them their
	 * pending save is fine would be true and useless.
	 */
	function useEvaluation() {
		var state = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var blockEditor = select( 'core/block-editor' );

			return {
				postId: editor.getCurrentPostId(),
				postType: editor.getCurrentPostType(),
				featuredMedia: editor.getEditedPostAttribute( 'featured_media' ),
				categories: editor.getEditedPostAttribute( 'categories' ),
				tags: editor.getEditedPostAttribute( 'tags' ),
				publishSidebarOpened: 'function' === typeof editor.isPublishSidebarOpened ? editor.isPublishSidebarOpened() : false,
				// Reference-compares cheaply and changes on any content edit.
				// Serializing here instead would run on every keystroke.
				blocks: blockEditor ? blockEditor.getBlocks() : null,
			};
		}, [] );

		var meta = useEntityProp( 'postType', state.postType, 'meta' );
		var metaValue = meta[ 0 ] || {};
		var setMeta = meta[ 1 ];

		var decorative = metaValue[ settings.decorativeMetaKey ] || [];

		var report = useState( null );
		var reportValue = report[ 0 ];
		var setReport = report[ 1 ];

		var loading = useState( false );
		var isLoading = loading[ 0 ];
		var setLoading = loading[ 1 ];

		var error = useState( null );
		var errorValue = error[ 0 ];
		var setError = error[ 1 ];

		var latest = useRef( {} );
		var scheduler = useRef( null );
		var hasStarted = useRef( false );
		var previousPublishSidebarOpened = useRef( false );

		var decorativeKey = decorative.join( '|' );
		var categoryKey = ( state.categories || [] ).join( ',' );
		var tagKey = ( state.tags || [] ).join( ',' );

		latest.current = {
			postId: state.postId || 0,
			postType: state.postType,
			featuredMedia: state.featuredMedia || 0,
			categories: ( state.categories || [] ).slice(),
			tags: ( state.tags || [] ).slice(),
			decorative: decorative.slice(),
		};

		if ( ! scheduler.current ) {
			scheduler.current = createEvaluationScheduler( {
				delay: DEBOUNCE_MS,
				now: Date.now,
				setTimer: function ( callback, delay ) {
					return window.setTimeout( callback, delay );
				},
				clearTimer: function ( handle ) {
					window.clearTimeout( handle );
				},
				buildPayload: function () {
					var snapshot = latest.current;

					return {
						post_id: snapshot.postId,
						post_type: snapshot.postType,
						// Always ask the publish question.
						status: 'publish',
						content: wp.data.select( 'core/editor' ).getEditedPostContent(),
						featured_media: snapshot.featuredMedia,
						categories: snapshot.categories,
						tags: snapshot.tags,
						decorative: snapshot.decorative,
					};
				},
				send: function ( payload ) {
					return apiFetch( {
						path: settings.restPath,
						method: 'POST',
						data: payload,
					} );
				},
				onStart: function () {
					hasStarted.current = true;
					setLoading( true );
				},
				onSuccess: function ( response ) {
					setReport( response );
					setError( null );
				},
				onError: function ( err ) {
					setError( err && err.message ? err.message : __( 'The checks could not be run.', 'rendar-prepublish-checks' ) );
				},
				onIdle: function () {
					setLoading( false );
				},
			} );
		}

		useEffect(
			function () {
				var publishSidebarJustOpened;
				var immediate;

				if ( ! state.postType || settings.postTypes.indexOf( state.postType ) === -1 ) {
					return;
				}

				publishSidebarJustOpened = state.publishSidebarOpened && ! previousPublishSidebarOpened.current;
				// Editor hydration can change the block collection before a zero-delay
				// timer runs. Keep initial priority until a request actually starts;
				// otherwise that hydration silently turns the first check into 4 seconds.
				immediate = ! hasStarted.current || publishSidebarJustOpened;
				previousPublishSidebarOpened.current = state.publishSidebarOpened;
				scheduler.current.schedule( immediate );
			},
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[ state.postId, state.postType, state.featuredMedia, state.blocks, state.publishSidebarOpened, categoryKey, tagKey, decorativeKey ]
		);

		useEffect( function () {
			return function () {
				if ( scheduler.current ) {
					scheduler.current.dispose();
				}
			};
		}, [] );

		return {
			report: reportValue,
			isLoading: isLoading,
			error: errorValue,
			decorative: decorative,
			metaValue: metaValue,
			setMeta: setMeta,
			postType: state.postType,
		};
	}

	/**
	 * Status glyph for one check.
	 */
	function statusMark( check ) {
		if ( check.status === 'pass' ) {
			return { text: '\u2713', className: 'is-pass', label: __( 'Passed', 'rendar-prepublish-checks' ) };
		}

		if ( check.status === 'not_applicable' ) {
			return { text: '\u2013', className: 'is-skipped', label: __( 'Skipped', 'rendar-prepublish-checks' ) };
		}

		if ( check.status === 'unavailable' ) {
			return { text: '?', className: 'is-unavailable', label: __( 'Could not be checked', 'rendar-prepublish-checks' ) };
		}

		if ( check.severity === 'error' ) {
			return { text: '\u2715', className: 'is-error', label: __( 'Must be fixed', 'rendar-prepublish-checks' ) };
		}

		return { text: '!', className: 'is-warning', label: __( 'Worth a look', 'rendar-prepublish-checks' ) };
	}

	/**
	 * Is an offender safe for the fields the editor renders directly?
	 *
	 * PHP validates this contract before returning a report. Keep this small
	 * client-side guard too: a stale cache or a non-core REST response must not
	 * turn an array/object label into an invalid React child.
	 */
	function isRenderableOffender( offender ) {
		return !! offender &&
			'object' === typeof offender &&
			'string' === typeof offender.key &&
			'' !== offender.key &&
			( ! Object.prototype.hasOwnProperty.call( offender, 'label' ) || 'string' === typeof offender.label ) &&
			( ! Object.prototype.hasOwnProperty.call( offender, 'edit_url' ) || 'string' === typeof offender.edit_url );
	}

	/**
	 * One offender row, with the decorative escape hatch where it applies.
	 */
	function OffenderRow( props ) {
		var offender = props.offender;
		var children = [
			el( 'span', { key: 'label', className: 'rendar-pc__offender-label' }, offender.label ),
		];

		if ( props.canMarkDecorative ) {
			children.push(
				el(
					Button,
					{
						key: 'decorative',
						variant: 'link',
						className: 'rendar-pc__decorative',
						onClick: function () {
							props.onMarkDecorative( offender.key );
						},
					},
					__( 'Decorative — no alt needed', 'rendar-prepublish-checks' )
				)
			);
		}

		if ( offender.edit_url ) {
			children.push(
				el(
					'a',
					{
						key: 'edit',
						href: offender.edit_url,
						target: '_blank',
						rel: 'noreferrer',
						className: 'rendar-pc__offender-edit',
					},
					__( 'Edit image', 'rendar-prepublish-checks' )
				)
			);
		}

		return el( 'li', { className: 'rendar-pc__offender' }, children );
	}

	/**
	 * The checks list.
	 */
	function CheckList( props ) {
		var report = props.report;

		if ( ! report.checks.length ) {
			return el( 'p', { className: 'rendar-pc__empty' }, __( 'No checks are enabled.', 'rendar-prepublish-checks' ) );
		}

		// Keep actionable failures at the top without changing the stable
		// registration order within each group.
		var checks = report.checks
			.map( function ( check, index ) {
				var priority = 2;

				if ( check.status === 'fail' ) {
					priority = check.severity === 'error' ? 0 : 1;
				}

				return { check: check, index: index, priority: priority };
			} )
			.sort( function ( first, second ) {
				return first.priority - second.priority || first.index - second.index;
			} )
			.map( function ( item ) {
				return item.check;
			} );

		return el(
			'ul',
			{ className: 'rendar-pc__list' },
			checks.map( function ( check ) {
				var mark = statusMark( check );
				var rows = [
					el(
						'div',
						{ key: 'head', className: 'rendar-pc__head' },
						el( 'span', { className: 'rendar-pc__mark ' + mark.className, 'aria-label': mark.label }, mark.text ),
						el( 'span', { className: 'rendar-pc__label' }, check.label )
					),
					el( 'p', { key: 'msg', className: 'rendar-pc__message' }, check.message ),
				];

				var offenders = Array.isArray( check.offenders ) ? check.offenders.filter( isRenderableOffender ) : [];

				if ( offenders.length ) {
					rows.push(
						el(
							'ul',
							{ key: 'offenders', className: 'rendar-pc__offenders' },
							offenders.map( function ( offender ) {
								return el( OffenderRow, {
									key: offender.key,
									offender: offender,
									canMarkDecorative: check.id === 'image-alt-text',
									onMarkDecorative: props.onMarkDecorative,
								} );
							} )
						)
					);
				}

				return el( 'li', { key: check.id, className: 'rendar-pc__check ' + mark.className }, rows );
			} )
		);
	}

	/**
	 * The override control.
	 *
	 * Arms a one-shot token in post meta; the publish request that follows
	 * carries it, the gate consumes it, and it is written to an audit log with
	 * the reason attached. It is not a switch that stays on.
	 */
	function Override( props ) {
		var report = props.report;
		var reason = useState( '' );
		var reasonValue = reason[ 0 ];
		var setReason = reason[ 1 ];

		var armed = !! props.metaValue[ settings.overrideMetaKey ];

		if ( ! report.can_override || ! report.would_block ) {
			return null;
		}

		if ( armed ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false, className: 'rendar-pc__override-armed' },
				el( 'p', {}, __( 'Publishing is authorized for the current failures. Press Publish to go ahead — the reason will be recorded against this article.', 'rendar-prepublish-checks' ) ),
				el(
					Button,
					{
						variant: 'link',
						isDestructive: true,
						onClick: function () {
							props.setOverride( '' );
						},
					},
					__( 'Cancel authorization', 'rendar-prepublish-checks' )
				)
			);
		}

		return el(
			'div',
			{ className: 'rendar-pc__override' },
			el( TextareaControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Publish anyway — reason', 'rendar-prepublish-checks' ),
				help: __( 'Recorded against this article with your name and the time.', 'rendar-prepublish-checks' ),
				value: reasonValue,
				onChange: setReason,
			} ),
			el(
				Button,
				{
					variant: 'secondary',
					disabled: ! reasonValue.trim(),
					onClick: function () {
						props.setOverride(
							JSON.stringify( {
								reason: reasonValue.trim(),
								checks: report.blocking_ids || report.failing_errors,
							} )
						);
					},
				},
				__( 'Authorize publishing', 'rendar-prepublish-checks' )
			)
		);
	}

	/**
	 * Panel body shared by both surfaces.
	 */
	function Body( props ) {
		var evaluation = props.evaluation;

		if ( evaluation.error ) {
			return el( Notice, { status: 'error', isDismissible: false }, evaluation.error );
		}

		if ( ! evaluation.report ) {
			return el(
				'p',
				{ className: 'rendar-pc__loading' },
				el( Spinner, {} ),
				__( 'Checking…', 'rendar-prepublish-checks' )
			);
		}

		var report = evaluation.report;
		var issue = props.issue;

		if ( ! report.in_scope ) {
			return el( 'p', {}, __( 'Pre-publish checks do not apply to this post type.', 'rendar-prepublish-checks' ) );
		}

		var children = [];

		if ( evaluation.isLoading ) {
			children.push( el( 'div', { key: 'busy', className: 'rendar-pc__busy' }, el( Spinner, {} ) ) );
		}

		if ( issue && issue.active && issue.issue ) {
			children.push( el( Notice, { key: 'issue', status: 'error', isDismissible: false }, issueMessage( issue ) ) );
		}

		if ( report.would_block ) {
			children.push(
				el(
					Notice,
					{ key: 'blocked', status: 'error', isDismissible: false },
					sprintf(
						/* translators: %d: number of checks. */
						wp.i18n._n(
							'%d blocking check must be resolved or explicitly overridden before publishing.',
							'%d blocking checks must be resolved or explicitly overridden before publishing.',
							( report.blocking_ids || report.failing_errors ).length,
							'rendar-prepublish-checks'
						),
						( report.blocking_ids || report.failing_errors ).length
					)
				)
			);
		} else if ( report.failing_errors.length && ! report.gated ) {
			// Never blocked, and the author is told which reason applies. Saying
			// so is the difference between trusting the panel and ignoring it.
			children.push(
				el(
					Notice,
					{ key: 'advisory', status: 'warning', isDismissible: false },
					report.predates_checks
						? __( 'This article was written before these checks existed, so they are advisory — publishing it will not be blocked.', 'rendar-prepublish-checks' )
						: __( 'This article is already published, so these are advisory — updating it will not be blocked.', 'rendar-prepublish-checks' )
				)
			);
		}

		if ( report.skipped_classic ) {
			children.push(
				el(
					'p',
					{ key: 'classic', className: 'rendar-pc__note' },
					__( 'This article\'s content is classic, not blocks, so the image checks were skipped.', 'rendar-prepublish-checks' )
				)
			);
		}

		children.push(
			el( CheckList, {
				key: 'list',
				report: report,
				onMarkDecorative: props.onMarkDecorative,
			} )
		);

		children.push(
			el( Override, {
				key: 'override',
				report: report,
				metaValue: evaluation.metaValue,
				setOverride: props.setOverride,
			} )
		);

		if ( report.override_log && report.override_log.length ) {
			children.push(
				el(
					'p',
					{ key: 'log', className: 'rendar-pc__note' },
					sprintf(
						/* translators: %d: number of recorded overrides. */
						wp.i18n._n(
							'%d publishing override has been recorded against this article.',
							'%d publishing overrides have been recorded against this article.',
							report.override_log.length,
							'rendar-prepublish-checks'
						),
						report.override_log.length
					)
				)
			);
		}

		return el( Fragment, {}, children );
	}

	/**
	 * The plugin: one evaluation, rendered into both panels.
	 */
	function PrepublishChecks() {
		var evaluation = useEvaluation();
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var issue = usePublishingIssue( postId, evaluation.postType );

		// Hooks above run unconditionally; gate after, not before.
		if ( ! evaluation.postType || settings.postTypes.indexOf( evaluation.postType ) === -1 ) {
			return null;
		}

		function writeMeta( key, value ) {
			var next = {};
			next[ key ] = value;
			evaluation.setMeta( Object.assign( {}, evaluation.metaValue, next ) );
		}

		function onMarkDecorative( key ) {
			if ( evaluation.decorative.indexOf( key ) !== -1 ) {
				return;
			}

			writeMeta( settings.decorativeMetaKey, evaluation.decorative.concat( [ key ] ) );
		}

		function setOverride( value ) {
			writeMeta( settings.overrideMetaKey, value );
		}

		var body = el( Body, {
			evaluation: evaluation,
			issue: issue,
			onMarkDecorative: onMarkDecorative,
			setOverride: setOverride,
		} );

		var failing = evaluation.report
			? evaluation.report.failing_errors.length + evaluation.report.failing_warnings.length
			: 0;

		var title = failing
			? sprintf(
				/* translators: %d: number of outstanding items. */
				__( 'Pre-Publish Checks (%d)', 'rendar-prepublish-checks' ),
				failing
			)
			: __( 'Pre-Publish Checks', 'rendar-prepublish-checks' );

		return el(
			Fragment,
			{},
			el(
				PluginDocumentSettingPanel,
				{ name: 'rendar-pc', title: title, className: 'rendar-pc' },
				body
			),
			PluginPrePublishPanel
				? el(
					PluginPrePublishPanel,
					{
						title: __( 'Pre-Publish Checks', 'rendar-prepublish-checks' ),
						initialOpen: true,
						className: 'rendar-pc',
					},
					body
				)
				: null
		);
	}

	registerPlugin( 'rendar-prepublish-checks', { render: PrepublishChecks } );
} )( window.wp );
