/* eslint-env jest */
/**
 * Every confirm and prompt dialog wears the shared shell's compact header:
 * End client, Mark as used, Save to folder, Is this about…, Merge … into…
 * and Stop watching.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import { renderOnPage } from './pageReads';
import { intelWire } from './intelWire';
import { ITEM, pageData } from '../views/__tests__/fixtures';
import EndClientDialog from '../components/EndClientDialog';
import FolderCard from '../components/FolderCard';
import ItemRowAlone, { ItemRows } from '../components/ItemRow';
import SaveToCollection from '../components/SaveToCollection';
import Review from '../views/Review';
import Watches, { CLOSED_WATCH } from '../views/Watches';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const PROPOSED = {
	entity_key: 'organization:valley-times',
	type: 'organization',
	name: 'Valley Times',
	items: [],
};

const WATCH = {
	watch_key: 'acme-complaints',
	name: 'Acme complaints',
	target: { kind: 'entity', key: 'organization:acme-quillsheet' },
	digest: true,
	instant: false,
	inbox: true,
	unseen: 0,
};

/**
 * Watches with the page's watch state, as IntelligencePage holds it.
 *
 * @return {import('react').ReactElement} The view.
 */
function WatchesPage() {
	const [ opened, setOpened ] = useState( CLOSED_WATCH );
	return (
		<Watches
			opened={ opened }
			onOpen={ setOpened }
			draft={ null }
			onDraft={ jest.fn() }
			onOpenEntity={ jest.fn() }
			onSave={ jest.fn() }
			canTune
		/>
	);
}

/**
 * Clicks the menu item `name` once the menu `toggle` opens.
 *
 * @param {string} toggle The menu toggle's name.
 * @param {string} name   The menu item's name.
 * @return {Promise<void>} Once it is clicked.
 */
const pickFrom = async ( toggle, name ) => {
	fireEvent.click( await screen.findByRole( 'button', { name: toggle } ) );
	fireEvent.click( await screen.findByRole( 'menuitem', { name } ) );
};

const OPENERS = [
	[
		'End client',
		async () => {
			render(
				<EndClientDialog
					name="Harbor Ledger"
					evidence={ [] }
					onEnd={ jest.fn() }
					onClose={ jest.fn() }
				/>
			);
			return screen.getByRole( 'dialog', {
				name: 'End Harbor Ledger as a client?',
			} );
		},
	],
	[
		'Mark as used',
		async () => {
			renderOnPage(
				<FolderCard
					item={ { ...ITEM, saved_ts: ITEM.item_ts, marks: [] } }
					others={ [] }
					canTune
					focused={ false }
					refusal={ null }
					onPickUp={ jest.fn() }
					onPutDown={ jest.fn() }
					onMove={ jest.fn() }
					onMark={ jest.fn() }
					onRemove={ jest.fn() }
				/>
			);
			await pickFrom( `Actions for ${ ITEM.title }`, 'LinkedIn post' );
			return screen.getByRole( 'dialog', { name: 'Mark as used' } );
		},
	],
	[
		'Save to folder',
		async () => {
			renderOnPage(
				<SaveToCollection item={ ITEM } onClose={ jest.fn() } />
			);
			await screen.findByLabelText( 'Folder name' );
			return screen.getByRole( 'dialog', { name: 'Save to folder' } );
		},
	],
	[
		'Is this about…',
		async () => {
			renderOnPage(
				<ItemRows items={ [ ITEM ] }>
					<ItemRowAlone
						item={ ITEM }
						onOpenEntity={ jest.fn() }
						canTune
					/>
				</ItemRows>
			);
			await pickFrom( 'More actions', 'Is this about…' );
			return screen.getByRole( 'dialog', { name: 'Is this about…' } );
		},
	],
	[
		'Merge … into…',
		async () => {
			renderOnPage( <Review canTune onOpenEntity={ jest.fn() } /> );
			fireEvent.click(
				screen.getByRole( 'tab', { name: /^Suggested names/ } )
			);
			fireEvent.click(
				await screen.findByRole( 'button', {
					name: 'Valley Times is the same as…',
				} )
			);
			return screen.getByRole( 'dialog', {
				name: 'Merge Valley Times into…',
			} );
		},
	],
	[
		'Stop watching',
		async () => {
			renderOnPage( <WatchesPage /> );
			fireEvent.click(
				await screen.findByRole( 'button', { name: WATCH.name } )
			);
			await screen.findByRole( 'region', { name: WATCH.name } );
			await pickFrom(
				`More actions for ${ WATCH.name }`,
				'Stop watching…'
			);
			return screen.getByRole( 'dialog', {
				name: `Stop watching ${ WATCH.name }?`,
			} );
		},
	],
];

// jsdom lays nothing out, so it has no scrollIntoView for the pickers.
const { scrollIntoView } = window.Element.prototype;

beforeEach( () => {
	window.Element.prototype.scrollIntoView = jest.fn();
	Core.reset();
	window.localStorage.clear();
	window.NewspackNodesData = pageData();
	const wire = intelWire();
	wire.replies.folders = { collections: [], available: true };
	wire.replies.collections = { collections: [], available: true };
	wire.replies.names = { entities: [], available: true };
	wire.replies.review = ( [ what ] ) =>
		'links' === what
			? { links: [], total: 0, available: true }
			: { entities: [ PROPOSED ], total: 1, available: true };
	wire.replies.theme_suggestions = {
		suggestions: [],
		total: 0,
		available: true,
	};
	wire.replies.watches = { watches: [ WATCH ], available: true };
	wire.replies.watch_matches = { items: [], total: 0, available: true };
	wire.replies.lenses = { lenses: [], available: true };
} );

afterEach( () => {
	window.Element.prototype.scrollIntoView = scrollIntoView;
} );

describe( 'compact dialog headers', () => {
	it.each( OPENERS )(
		"each confirm and prompt dialog's header is compact: %s",
		async ( _, open ) => {
			const dialog = await open();
			expect(
				within( dialog ).getByRole( 'banner', { hidden: true } )
			).toHaveClass(
				'newspack-nodes-modal__header',
				'newspack-nodes-modal__header--compact'
			);
		}
	);
} );
