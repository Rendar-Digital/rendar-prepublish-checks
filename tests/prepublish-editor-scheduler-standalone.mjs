import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(
	new URL( '../assets/js/editor.js', import.meta.url ),
	'utf8'
);
let assertions = 0;

function stubWp() {
	return {
		plugins: { registerPlugin() {} },
		editor: { PluginDocumentSettingPanel() {}, PluginPrePublishPanel() {} },
		element: {
			createElement() {},
			Fragment: {},
			useState() {},
			useEffect() {},
			useRef() {},
		},
		data: { useSelect() {} },
		coreData: { useEntityProp() {} },
		apiFetch() {},
		i18n: { __( value ) { return value; }, sprintf() {}, _n() {} },
		components: { Button() {}, Spinner() {}, TextareaControl() {}, Notice() {} },
	};
}

const windowWithHooks = {
	rendarPrepublish: {},
	rendarPrepublishTestHooks: {},
	wp: stubWp(),
};
vm.runInNewContext( source, { window: windowWithHooks, Promise, JSON, Math, Date } );
const createScheduler = windowWithHooks.rendarPrepublishTestHooks.createEvaluationScheduler;
assert.equal( typeof createScheduler, 'function', 'The real editor source exposes its scheduler only to a pre-created test seam.' );
assertions++;

const productionWindow = { rendarPrepublish: {}, wp: stubWp() };
vm.runInNewContext( source, { window: productionWindow, Promise, JSON, Math, Date } );
assert.equal( productionWindow.rendarPrepublishTestHooks, undefined, 'Normal output exposes no scheduler test global.' );
assertions++;
assert.match( source, /settings\.postTypes\.indexOf\( state\.postType \) === -1/, 'The hook adapter retains its unsupported-post-type guard.' );
assertions++;

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}

function makeHarness( initialPayload = { value: 'initial' } ) {
	let now = 0;
	let nextTimer = 1;
	let payload = initialPayload;
	let active = 0;
	let maxActive = 0;
	const timers = new Map();
	const requests = [];
	const starts = [];
	const successes = [];
	const errors = [];
	const idles = [];

	function setTimer( callback, delay ) {
		const id = nextTimer++;
		timers.set( id, { at: now + delay, callback } );
		return id;
	}

	function clearTimer( id ) {
		timers.delete( id );
	}

	async function flush() {
		await Promise.resolve();
		await Promise.resolve();
	}

	async function advance( amount ) {
		const target = now + amount;

		while ( true ) {
			const due = [ ...timers.entries() ]
				.filter( ( entry ) => entry[ 1 ].at <= target )
				.sort( ( first, second ) => first[ 1 ].at - second[ 1 ].at || first[ 0 ] - second[ 0 ] )[ 0 ];

			if ( ! due ) {
				break;
			}

			now = due[ 1 ].at;
			timers.delete( due[ 0 ] );
			due[ 1 ].callback();
			await flush();
		}

		now = target;
		await flush();
	}

	const scheduler = createScheduler( {
		delay: 4000,
		now: () => now,
		setTimer,
		clearTimer,
		buildPayload: () => payload,
		send( sentPayload ) {
			const request = deferred();
			active++;
			maxActive = Math.max( maxActive, active );
			requests.push( { payload: sentPayload, request } );
			request.promise.then(
				() => active--,
				() => active--
			);
			return request.promise;
		},
		onStart: () => starts.push( now ),
		onSuccess: ( response ) => successes.push( { at: now, response } ),
		onError: ( error ) => errors.push( { at: now, error } ),
		onIdle: () => idles.push( now ),
	} );

	return {
		scheduler,
		advance,
		flush,
		setPayload( next ) { payload = next; },
		get payload() { return payload; },
		get requests() { return requests; },
		get starts() { return starts; },
		get successes() { return successes; },
		get errors() { return errors; },
		get idles() { return idles; },
		get maxActive() { return maxActive; },
		get timers() { return timers; },
	};
}

// Immediate initial request, successful paint, and exact-current dedupe.
{
	const h = makeHarness();
	h.scheduler.schedule( true );
	await h.advance( 0 );
	assert.equal( h.requests.length, 1 );
	assert.deepEqual( h.requests[ 0 ].payload, { value: 'initial' } );
	assertions += 2;
	h.requests[ 0 ].request.resolve( { report: 1 } );
	await h.flush();
	assert.deepEqual( h.successes, [ { at: 0, response: { report: 1 } } ] );
	assert.equal( h.idles.length, 1 );
	assertions += 2;
	h.scheduler.schedule( false );
	await h.advance( 4000 );
	assert.equal( h.requests.length, 1, 'An exact last-successful payload is not sent twice.' );
	assertions++;
}

// The trailing deadline moves with every edit and sends only the latest payload.
{
	const h = makeHarness();
	h.scheduler.schedule( false );
	await h.advance( 3000 );
	h.setPayload( { value: 'latest' } );
	h.scheduler.schedule( false );
	await h.advance( 3999 );
	assert.equal( h.requests.length, 0 );
	await h.advance( 1 );
	assert.equal( h.requests.length, 1 );
	assert.deepEqual( h.requests[ 0 ].payload, { value: 'latest' } );
	assertions += 3;
}

// One in flight, stale success suppressed, then exactly one latest request.
{
	const h = makeHarness( { value: 'first' } );
	h.scheduler.schedule( true );
	await h.advance( 0 );
	h.setPayload( { value: 'second' } );
	h.scheduler.schedule( false );
	await h.advance( 1000 );
	h.setPayload( { value: 'latest' } );
	h.scheduler.schedule( false ); // due at t=5000
	await h.advance( 4000 );
	assert.equal( h.requests.length, 1, 'No request starts while the first remains active.' );
	h.requests[ 0 ].request.resolve( { report: 'stale' } );
	await h.flush();
	assert.equal( h.successes.length, 0, 'A stale success never paints.' );
	assert.equal( h.idles.length, 0, 'Loading remains active while the stale response queues latest-state work.' );
	assertions++;
	await h.advance( 0 );
	assert.equal( h.requests.length, 2 );
	assert.deepEqual( h.requests[ 1 ].payload, { value: 'latest' } );
	assert.equal( h.maxActive, 1 );
	assertions += 5;
}

// Settling before the latest idle deadline waits out the remainder.
{
	const h = makeHarness( { value: 'first' } );
	h.scheduler.schedule( true );
	await h.advance( 0 );
	await h.advance( 1000 );
	h.setPayload( { value: 'second' } );
	h.scheduler.schedule( false ); // due at t=5000
	await h.advance( 2000 );
	h.requests[ 0 ].request.resolve( { report: 'stale' } );
	await h.flush();
	await h.advance( 1999 );
	assert.equal( h.requests.length, 1 );
	await h.advance( 1 );
	assert.equal( h.requests.length, 2 );
	assertions += 2;
}

// A stale failure is hidden and does not prevent the latest trailing request.
{
	const h = makeHarness( { value: 'first' } );
	h.scheduler.schedule( true );
	await h.advance( 0 );
	h.setPayload( { value: 'latest' } );
	h.scheduler.schedule( false );
	await h.advance( 4000 );
	h.requests[ 0 ].request.reject( new Error( 'stale failure' ) );
	await h.flush();
	assert.equal( h.errors.length, 0 );
	await h.advance( 0 );
	assert.equal( h.requests.length, 2 );
	assertions += 2;
}

// A current failure paints once, never loops, and retries only on a new event.
{
	const h = makeHarness();
	h.scheduler.schedule( true );
	await h.advance( 0 );
	h.requests[ 0 ].request.reject( new Error( 'current failure' ) );
	await h.flush();
	assert.equal( h.errors.length, 1 );
	await h.advance( 20000 );
	assert.equal( h.requests.length, 1, 'Failure settlement does not create a retry loop.' );
	h.scheduler.schedule( false );
	await h.advance( 4000 );
	assert.equal( h.requests.length, 2, 'A later state event may retry the current payload.' );
	assertions += 3;
}

// Publish intent moves changed state ahead of its ordinary deadline but still dedupes.
{
	const h = makeHarness();
	h.scheduler.schedule( true );
	await h.advance( 0 );
	h.requests[ 0 ].request.resolve( { report: 1 } );
	await h.flush();
	h.setPayload( { value: 'changed' } );
	h.scheduler.schedule( false );
	await h.advance( 1000 );
	h.scheduler.schedule( true );
	await h.advance( 0 );
	assert.equal( h.requests.length, 2, 'Publish intent flushes changed state immediately.' );
	h.requests[ 1 ].request.resolve( { report: 2 } );
	await h.flush();
	h.scheduler.schedule( true );
	await h.advance( 0 );
	assert.equal( h.requests.length, 2, 'Publish intent does not resend an identical successful payload.' );
	assertions += 2;
}

// Returning to the active payload lets that response become current, with no trailing send.
{
	const h = makeHarness( { value: 'stable' } );
	h.scheduler.schedule( true );
	await h.advance( 0 );
	h.setPayload( { value: 'temporary' } );
	h.scheduler.schedule( false );
	h.setPayload( { value: 'stable' } );
	h.scheduler.schedule( false );
	h.requests[ 0 ].request.resolve( { report: 'current-again' } );
	await h.flush();
	await h.advance( 4000 );
	assert.equal( h.successes.length, 1 );
	assert.equal( h.requests.length, 1 );
	assertions += 2;
}

// Dispose clears queued work and suppresses callbacks from active work.
{
	const queued = makeHarness();
	queued.scheduler.schedule( false );
	queued.scheduler.dispose();
	await queued.advance( 5000 );
	assert.equal( queued.requests.length, 0 );
	assert.equal( queued.timers.size, 0 );
	assertions += 2;

	const active = makeHarness();
	active.scheduler.schedule( true );
	await active.advance( 0 );
	active.scheduler.dispose();
	active.requests[ 0 ].request.resolve( { report: 'ignored' } );
	await active.flush();
	assert.equal( active.successes.length, 0 );
	assert.equal( active.idles.length, 0 );
	assertions += 2;
}

console.log( `PASS: ${ assertions } prepublish editor scheduler assertions` );
