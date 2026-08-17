import { CommandInterpreterNode } from '@newspack-nodes/runtime';
import { views } from '../register';

describe( 'dashboard node registration', () => {
	// Each shell name must map to its OWN class; a swap must fail here.
	it.each( [
		[ 'SourceCountsView', { sources: {} } ],
		[ 'TopTableView', { top: {} } ],
		[
			'AccumulatedView',
			{ accumulated: 0, done: 0, total: 0, digest: '' },
		],
	] )(
		'%s registers its exact class and constructs with the right empty slice',
		( shellName, emptySlice ) => {
			const registered = CommandInterpreterNode.includeNodes[ shellName ];
			expect( registered ).toBe( views[ shellName ] );
			const node = new registered();
			expect( node.emptySlice() ).toEqual( emptySlice );
		}
	);

	it( 'each view carries its own palette description', () => {
		const described = [
			'SourceCountsView',
			'TopTableView',
			'AccumulatedView',
		].map( ( name ) => views[ name ].nodeSchema().description );

		expect( new Set( described ).size ).toBe( described.length );
	} );

	it( 'no longer registers the retired god view node', () => {
		expect(
			CommandInterpreterNode.includeNodes.InsightsView
		).toBeUndefined();
	} );
} );
