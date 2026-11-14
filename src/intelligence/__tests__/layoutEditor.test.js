/* eslint-env jest */
/**
 * The layout editor's bundle: it registers the blocks of the one context
 * the server localized, each against its view, and the editor for that
 * context, which edits the layout under the page's own heading with every
 * block reading the page it previews, live and with its writes inert.
 *
 * `@wordpress/block-editor` is a stand-in whose block list renders each
 * top-level block's registered edit, because the real one needs the single
 * `wp.privateApis` copy WordPress hands every package; the blocks, the
 * composition commands and the reads run for real over the command double.
 */

import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { renderOnPage } from './pageReads';
import { PageReads } from '../hooks/usePageRead';
import {
	createBlock,
	getBlockTypes,
	unregisterBlockType,
} from '@wordpress/blocks';
import { useContext } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import CompositionEditSlot from '@newspack-nodes/shared/composition/CompositionEditSlot';
import { intelWire } from './intelWire';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import { useIntelPage } from '../layout/PageContext';
import { BLOCK_VIEWS } from '../blocks';
import { mountLayoutEditor } from '../layout/LayoutEditor';
import { fakeWordPress } from '@newspack-nodes/shared/test-utils/fakeWordPress';

const mockProvider = { props: null };

jest.mock( '@wordpress/block-editor', () => {
	const { getBlockType } = jest.requireActual( '@wordpress/blocks' );
	const pass = ( { children } ) => <div>{ children }</div>;
	return {
		BlockEditorProvider: ( props ) => {
			mockProvider.props = props;
			return props.children;
		},
		BlockTools: pass,
		WritingFlow: pass,
		ObserveTyping: pass,
		BlockList: () =>
			( mockProvider.props?.value ?? [] ).map( ( block ) => {
				const { edit: Edit } = getBlockType( block.name );
				return (
					<Edit
						key={ block.clientId }
						clientId={ block.clientId }
						attributes={ block.attributes }
						setAttributes={ () => {} }
					/>
				);
			} ),
		BlockInspector: () => null,
		Inserter: () => null,
		InspectorControls: () => null,
		InnerBlocks: () => null,
		useBlockProps: () => ( {} ),
	};
} );

const PROBE = 'newspack-intelligence/probe';

/**
 * The edit slot as the page mounts it, handed the page's shared reads.
 *
 * @param {Object} props What the slot hands the editor.
 * @return {import('react').ReactElement} The slot.
 */
function PageEditSlot( props ) {
	return (
		<CompositionEditSlot { ...props } reads={ useContext( PageReads ) } />
	);
}

/**
 * A block reading its page's team view.
 *
 * @param {Object} props
 * @param {Object} props.attributes       Its attributes.
 * @param {string} props.attributes.label What it names.
 * @return {import('react').ReactElement} The block.
 */
function Probe( { attributes: { label } } ) {
	const { lens_key: lens } = useIntelPage();
	const read = useIntelRead(
		`probe:${ label }`,
		'signals',
		commandArgs( { lens } )
	);
	return (
		<section>
			<p>{ `${ label }: ${ read.answer ? 'read' : 'owed' }` }</p>
		</section>
	);
}

/** What `Composition::schemas( 'newspack-intelligence/lens' )` localizes. */
const LENS_SCHEMAS = {
	context: 'newspack-intelligence/lens',
	max_blocks: 64,
	inline: [ 'strong', 'em', 'a' ],
	blocks: {
		'core/paragraph': {
			title: 'Paragraph',
			attributes: {},
			container: false,
			multiple: true,
			core: true,
			text: 'p',
		},
		[ PROBE ]: {
			title: 'Probe',
			attributes: { label: { type: 'string', default: '' } },
			container: false,
			multiple: true,
		},
		'newspack-intelligence/watches': {
			title: 'Your watches',
			attributes: {
				heading: { type: 'string', default: 'Your watches' },
				max: { type: 'integer', default: 5 },
			},
			container: false,
			multiple: false,
		},
	},
};

const SAVED = {
	context: 'newspack-intelligence/lens',
	source: 'saved',
	blocks: [
		{ name: PROBE, attributes: { label: 'Kestrel' }, inner: [] },
		{ name: PROBE, attributes: { label: 'Heron' }, inner: [] },
		{
			name: 'newspack-intelligence/watches',
			attributes: { heading: 'Kept close', max: 3 },
			inner: [],
		},
	],
	saved_ts: 1912345678.5,
	actor: 'lakeside-editor',
};

const LENSES = {
	lenses: [
		{ lens_key: 'desk-watch', name: 'Desk Watch' },
		{ lens_key: 'harbor-desk', name: 'Harbor desk' },
	],
	available: true,
};

const WAIT = { timeout: 4000 };

let wire;

// jsdom lays nothing out, so it has no scrollIntoView for the entity picker.
const { scrollIntoView } = window.Element.prototype;

beforeAll( () => {
	window.Element.prototype.scrollIntoView = jest.fn();
} );

afterAll( () => {
	window.Element.prototype.scrollIntoView = scrollIntoView;
} );

beforeEach( () => {
	window.wp = fakeWordPress();
	Core.reset();
	mockProvider.props = null;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.get = SAVED;
	wire.replies.lenses = LENSES;
	wire.replies.signals = { signals: [], available: true };
	wire.replies.watches = { watches: [], available: true };
	window.__newspackNodesCompositionEditors = undefined;
} );

afterEach( () => {
	getBlockTypes()
		.filter( ( { name } ) =>
			[ 'newspack-intelligence/', 'newspack-nodes/' ].some( ( prefix ) =>
				name.startsWith( prefix )
			)
		)
		.forEach( ( { name } ) => unregisterBlockType( name ) );
} );

describe( 'mountLayoutEditor', () => {
	it( 'registers the localized blocks alone, each against its view, leaving core’s to core', () => {
		mountLayoutEditor(
			{
				...LENS_SCHEMAS,
				blocks: {
					'newspack-intelligence/watches':
						LENS_SCHEMAS.blocks[ 'newspack-intelligence/watches' ],
					'core/paragraph': LENS_SCHEMAS.blocks[ 'core/paragraph' ],
				},
			},
			BLOCK_VIEWS
		);

		expect( getBlockTypes().map( ( { name } ) => name ) ).toEqual( [
			'newspack-intelligence/watches',
		] );
	} );

	it( 'refuses a localized block with no view', () => {
		expect( () => mountLayoutEditor( LENS_SCHEMAS, BLOCK_VIEWS ) ).toThrow(
			`${ PROBE } has no view`
		);
	} );

	it( 'previews the team view it was opened from, and re-reads every block on another', async () => {
		mountLayoutEditor( LENS_SCHEMAS, {
			...BLOCK_VIEWS,
			[ PROBE ]: { View: Probe },
		} );
		renderOnPage(
			<PageEditSlot
				context="newspack-intelligence/lens"
				target={ { page: 'lens', lens_key: 'desk-watch' } }
				since={ null }
				onDone={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Edit the team view layout' } )
		).toBeTruthy();
		await waitFor(
			() =>
				expect( wire.sent( 'signals' ) ).toEqual( [
					[ '--lens=desk-watch' ],
					[ '--lens=desk-watch' ],
				] ),
			WAIT
		);
		const preview = await screen.findByRole(
			'button',
			{ name: 'Preview with Desk Watch' },
			WAIT
		);
		expect( await screen.findByText( 'Kestrel: read' ) ).toBeTruthy();
		expect( screen.getByText( 'Heron: read' ) ).toBeTruthy();
		screen
			.getAllByRole( 'button', { name: 'New watch' } )
			.forEach( ( button ) =>
				expect( button ).toHaveAttribute( 'aria-disabled', 'true' )
			);

		fireEvent.click( preview );
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Harbor desk' } )
		);

		await waitFor(
			() =>
				expect( wire.sent( 'signals' ).slice( 2 ) ).toEqual( [
					[ '--lens=harbor-desk' ],
					[ '--lens=harbor-desk' ],
				] ),
			WAIT
		);
		expect(
			screen.getByRole( 'button', { name: 'Preview with Harbor desk' } )
		).toBeTruthy();
	} );

	/**
	 * The profile editor previewing Harbor Ledger, its picker opened, where
	 * `names` offers Lakeside Works.
	 *
	 * @return {Promise<HTMLElement>} The picker's box.
	 */
	async function openEntityPreview() {
		const ENTITY_PROBE = 'newspack-intelligence/entity-probe';
		/** A block reading its page's entity. */
		function EntityProbe() {
			const { entity_key: key } = useIntelPage();
			useIntelRead( 'probe:entity', 'related', key ? [ key ] : null );
			return null;
		}
		wire.replies.get = {
			...SAVED,
			context: 'newspack-intelligence/entity',
			blocks: [ { name: ENTITY_PROBE, attributes: {}, inner: [] } ],
		};
		wire.replies.entity = ( [ key ] ) => ( {
			name:
				'organization:harbor-ledger' === key
					? 'Harbor Ledger'
					: 'Lakeside Works',
			available: true,
		} );
		wire.replies.names = {
			entities: [
				{
					entity_key: 'organization:lakeside-works',
					name: 'Lakeside Works',
				},
			],
			available: true,
		};
		wire.replies.related = { entities: [], available: true };
		mountLayoutEditor(
			{
				context: 'newspack-intelligence/entity',
				max_blocks: 24,
				blocks: {
					[ ENTITY_PROBE ]: {
						title: 'Entity probe',
						attributes: {},
						container: false,
						multiple: true,
					},
				},
			},
			{ [ ENTITY_PROBE ]: { View: EntityProbe } }
		);
		renderOnPage(
			<PageEditSlot
				context="newspack-intelligence/entity"
				target={ {
					page: 'entity',
					entity_key: 'organization:harbor-ledger',
				} }
				since={ null }
				onDone={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Edit the profile layout' } )
		).toBeTruthy();
		fireEvent.click(
			await screen.findByRole(
				'button',
				{ name: 'Preview with Harbor Ledger' },
				WAIT
			)
		);
		return screen.getByRole( 'combobox', { name: 'Preview with' } );
	}

	it( 'previews the profile it was opened from, and another picked by name at once', async () => {
		const box = await openEntityPreview();
		act( () => box.focus() );
		fireEvent.change( box, { target: { value: 'Lakeside' } } );
		fireEvent.click(
			await screen.findByRole(
				'option',
				{ name: 'Lakeside Works' },
				WAIT
			)
		);

		await waitFor(
			() =>
				expect( wire.sent( 'related' ) ).toEqual( [
					[ 'organization:harbor-ledger' ],
					[ 'organization:lakeside-works' ],
				] ),
			WAIT
		);
		expect(
			await screen.findByRole(
				'button',
				{ name: 'Preview with Lakeside Works' },
				WAIT
			)
		).toBeTruthy();
	} );

	it( 'closes the picker on Escape once its list is closed, the list first', async () => {
		const box = await openEntityPreview();
		act( () => box.focus() );
		fireEvent.change( box, { target: { value: 'Lakeside' } } );
		await screen.findByRole( 'option', { name: 'Lakeside Works' }, WAIT );

		fireEvent.keyDown( box, { key: 'Escape', code: 'Escape' } );
		expect( screen.queryByRole( 'listbox' ) ).toBeNull();
		expect( box ).toBeInTheDocument();

		fireEvent.keyDown( box, { key: 'Escape', code: 'Escape' } );
		await waitFor( () => expect( box ).not.toBeInTheDocument(), WAIT );
		expect( wire.sent( 'related' ) ).toEqual( [
			[ 'organization:harbor-ledger' ],
		] );
	} );

	it( 'tells the page whether the layout holds a change not yet saved', async () => {
		wire.replies.get = { ...SAVED, blocks: [] };
		mountLayoutEditor(
			{
				context: 'newspack-intelligence/briefing',
				max_blocks: 24,
				blocks: {
					'newspack-intelligence/watches':
						LENS_SCHEMAS.blocks[ 'newspack-intelligence/watches' ],
				},
				inline: [ 'strong' ],
			},
			BLOCK_VIEWS
		);
		const told = [];
		renderOnPage(
			<PageEditSlot
				context="newspack-intelligence/briefing"
				target={ { page: 'briefing' } }
				since={ 1792000000 }
				onDone={ () => {} }
				onDirty={ ( dirty ) => told.push( dirty ) }
			/>
		);
		await waitFor(
			() => expect( mockProvider.props?.value ).toEqual( [] ),
			WAIT
		);
		expect( told ).toEqual( [ false ] );

		act( () =>
			mockProvider.props.onInput( [
				createBlock( 'newspack-intelligence/watches', {
					heading: 'Kept 7956',
					max: 3,
				} ),
			] )
		);

		expect( told ).toEqual( [ false, true ] );
	} );

	it( 'heads each page in its own words', async () => {
		wire.replies.get = { ...SAVED, blocks: [] };
		mountLayoutEditor(
			{
				context: 'newspack-intelligence/briefing',
				max_blocks: 24,
				blocks: {},
			},
			BLOCK_VIEWS
		);
		renderOnPage(
			<PageEditSlot
				context="newspack-intelligence/briefing"
				target={ { page: 'briefing' } }
				since={ 1792000000 }
				onDone={ () => {} }
			/>
		);

		await waitFor(
			() => expect( mockProvider.props?.value ).toEqual( [] ),
			WAIT
		);
		expect(
			screen.getByRole( 'heading', { name: 'Edit the Briefing layout' } )
		).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: /Preview with/ } ) ).toBe(
			null
		);
	} );
} );

describe( 'the layout-editor bundle', () => {
	it( 'mounts the editor of the context the server localized, its blocks alone', async () => {
		wire.replies.get = {
			...SAVED,
			context: 'newspack-intelligence/briefing',
			blocks: [],
		};
		window.NewspackNodesComposition = {
			context: 'newspack-intelligence/briefing',
			max_blocks: 24,
			blocks: {
				'newspack-intelligence/health': {
					title: 'Collection status',
					attributes: {
						detail: { type: 'string', default: 'sources' },
					},
					container: false,
					multiple: false,
				},
			},
		};

		require( '../layout-editor' );

		expect(
			getBlockTypes().map( ( { name, title } ) => [ name, title ] )
		).toEqual( [
			[ 'newspack-intelligence/health', 'Collection status' ],
		] );
		renderOnPage(
			<PageEditSlot
				context="newspack-intelligence/briefing"
				target={ { page: 'briefing' } }
				since={ 1792000000 }
				onDone={ () => {} }
			/>
		);
		expect(
			screen.getByRole( 'heading', { name: 'Edit the Briefing layout' } )
		).toBeTruthy();
		await waitFor(
			() => expect( mockProvider.props?.value ).toEqual( [] ),
			WAIT
		);
	} );
} );
