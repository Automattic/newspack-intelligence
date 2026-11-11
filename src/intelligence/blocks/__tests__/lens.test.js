/* eslint-env jest */
/**
 * `useSavedLens` answers the saved team view a key names from the page's one
 * `lenses` read: undefined while it is owed, null for no key, a key it does
 * not list or a read it refused, and the view otherwise.
 */

import { renderHook } from '@testing-library/react';
import { PageReads } from '../../hooks/usePageRead';
import { useSavedLens } from '../lens';

const KINGFISHER = { lens_key: 'kingfisher-desk', name: 'Kingfisher desk' };

const savedLens = ( key, lenses ) =>
	renderHook( () => useSavedLens( key ), {
		wrapper: ( { children } ) => (
			<PageReads.Provider value={ { lenses } }>
				{ children }
			</PageReads.Provider>
		),
	} ).result.current;

describe( 'useSavedLens', () => {
	it( 'answers the view its key names', () => {
		expect(
			savedLens( 'kingfisher-desk', {
				answer: { lenses: [ KINGFISHER ] },
			} )
		).toBe( KINGFISHER );
	} );

	it( 'answers undefined while the lenses are owed', () => {
		expect( savedLens( 'kingfisher-desk', { answer: null } ) ).toBe(
			undefined
		);
	} );

	it( 'answers null for a key the lenses do not list', () => {
		expect(
			savedLens( 'heron-desk', { answer: { lenses: [ KINGFISHER ] } } )
		).toBeNull();
	} );

	it( 'answers null for no key, without reading the lenses', () => {
		expect( savedLens( '', { answer: null } ) ).toBeNull();
	} );

	it( 'answers null when the lenses read was refused', () => {
		expect(
			savedLens( 'kingfisher-desk', {
				error: 'no lens read for you',
				answer: { lenses: [ KINGFISHER ] },
			} )
		).toBeNull();
	} );
} );
