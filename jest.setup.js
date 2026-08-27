/**
 * jsdom ships no TextEncoder; @noble/hashes (the substrate's command signer)
 * needs one. Node's real implementation, so the suite exercises what the
 * browser will. Mirrors newspack-nodes/jest.setup.js.
 */
const { TextEncoder, TextDecoder } = require( 'util' );
global.TextEncoder = global.TextEncoder || TextEncoder;
global.TextDecoder = global.TextDecoder || TextDecoder;

/* eslint-env jest */
// jest-dom custom matchers (toBeInTheDocument, etc.) for the React dashboard tests.
import '@testing-library/jest-dom';

// Jest setup — FAIL any test that emits an unexpected console.warn or
// console.error (mirrors the sibling newspack-nodes setup).
//
// The substrate's `Core.stderr()` and `printLessOften()`
// (newspack-nodes/src/runtime/core.js) route node faults, rate-limited logs, and
// dropped-message notices through console.warn (never console.error, to skip
// devtools' error counter), each line stamped `YYYY-MM-DD HH:MM:SS <zone> <argv0>: `.
// Those are expected spam on any test exercising a fault path, so warn lines
// matching that signature are dropped. EVERY other console.warn and EVERY
// console.error (React `act(...)` warnings, third-party deprecations like
// @wordpress/components' 36px notice, genuine errors) is recorded and re-thrown
// in afterEach, failing the test. Throwing in afterEach — not inside the mock —
// keeps React's render/commit from swallowing the throw or cascading into
// confusing secondary failures, and the captured Error preserves the call site.
//
// Tests that legitimately assert on console.warn/error install their own
// `jest.spyOn( console, … )`; that shadows the recorder for that test and the
// afterEach restore unwinds both.

// The Core.stderr() line prefix: ISO-ish date + " <zone> <argv0>: ".
// The zone token is constrained to the shapes Intl actually emits — a bare
// `\S+` there matches any `<date> <time> <word> <word>: ` warning text and
// strips it, which is the gate swallowing the very lines it exists to report.
const SUBSTRATE_STDERR =
	/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d (?:UTC|GMT[+-][\d:]+|[A-Z]{2,5}) \S+: /;

let violations = [];

const record =
	( channel ) =>
	( ...args ) => {
		if (
			'warn' === channel &&
			'string' === typeof args[ 0 ] &&
			SUBSTRATE_STDERR.test( args[ 0 ] )
		) {
			return;
		}
		violations.push(
			new Error(
				`Unexpected console.${ channel }: ${ args
					.map( String )
					.join( ' ' ) }`
			)
		);
	};

beforeEach( () => {
	violations = [];
	jest.spyOn( console, 'warn' ).mockImplementation( record( 'warn' ) );
	jest.spyOn( console, 'error' ).mockImplementation( record( 'error' ) );
} );

afterEach( () => {
	const captured = violations;
	violations = [];
	if ( jest.isMockFunction( console.warn ) ) {
		console.warn.mockRestore();
	}
	if ( jest.isMockFunction( console.error ) ) {
		console.error.mockRestore();
	}
	if ( captured.length ) {
		throw captured[ 0 ];
	}
} );

// @longform
// The substrate's emitters hold until authenticated, and this plugin inlines
// that runtime — so the harness authenticates too, or every poll test asserts
// silence. Guarded on `window`: node-environment suites must not pull in the
// browser runtime graph. Mirrors newspack-nodes/jest.setup.js.
if ( 'undefined' !== typeof window ) {
	const auth = require( '@newspack-nodes/runtime' );
	beforeEach( async () => {
		auth.forgetSession();
		auth.__setAuthFetch( async () => ( {
			handle: 'e2e11111e2e22222e2e33333e2e44444',
			key: 'jest-harness-session-key',
			expires_in: 3600,
			now: Math.floor( Date.now() / 1000 ),
		} ) );
		await auth.ensureSession();
	} );
}
