import { word, words } from '../words';

describe( 'words', () => {
	beforeEach( () => {
		window.NewspackNodesData = {
			vocabulary: { tideWords: { ebb: 'Falling water' } },
		};
	} );

	afterEach( () => {
		delete window.NewspackNodesData;
	} );

	it( 'answers the map the vocabulary sends', () => {
		expect( words( 'tideWords' ) ).toEqual( { ebb: 'Falling water' } );
	} );

	it( 'throws for a map the vocabulary does not send', () => {
		expect( () => words( 'moonWords' ) ).toThrow(
			'no moonWords in the vocabulary'
		);
	} );

	it( 'answers the word for a key the map words', () => {
		expect( word( 'tideWords', 'ebb' ) ).toBe( 'Falling water' );
	} );

	it( 'throws for a key the map does not word', () => {
		expect( () => word( 'tideWords', 'flood' ) ).toThrow(
			'no tideWords word for "flood"'
		);
	} );

	it( 'never reads a key off the prototype as a word', () => {
		expect( () => word( 'tideWords', 'toString' ) ).toThrow(
			'no tideWords word for "toString"'
		);
	} );
} );
