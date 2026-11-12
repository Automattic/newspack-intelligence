/**
 * What a block on a composed page reads besides its attributes: the page,
 * its subject, the reader's last look and the page's actions a block's rows
 * open; and, from the `editing` prop the substrate hands each block's View,
 * whether its layout is being edited, in which case every write control the
 * block holds is inert, so editing a layout writes nothing else.
 */

import { createContext, useContext } from '@wordpress/element';

/**
 * The page a block stands on.
 *
 * @typedef {Object} IntelPage
 * @property {?('briefing'|'lens'|'entity')} page       The page.
 * @property {?string}                       lens_key   The team view it shows, on a team view's page.
 * @property {?string}                       entity_key The entity it profiles, on a profile.
 * @property {?number}                       since      The reader's last look, UTC seconds, on the Briefing.
 * @property {boolean}                       [kept]     Whether the browser keeps the last look; false hides what arrived since.
 * @property {Partial<IntelActions>}         [actions]  The page's actions; the editor's preview offers none.
 */

/**
 * What a block's rows open, and the ask the page holds.
 *
 * @typedef {Object} IntelActions
 * @property {(key: string) => void}     onOpenEntity Opens a profile.
 * @property {(key: ?string) => void}    onOpenLens   Opens a team view, or the list for null.
 * @property {(key: string) => void}     onOpenWatch  Opens a watch.
 * @property {() => void}                onNewWatch   Opens the form for a new watch.
 * @property {(filters: Object) => void} onSearchFor  Opens Search on filters.
 * @property {() => void}                onSearch     Opens Search.
 * @property {(item: Object) => void}    [onSave]     Saves an item; absent for a reader.
 * @property {boolean}                   canTune      Whether the reader may curate.
 * @property {?Object}                   asked        The ask the page holds.
 * @property {(asked: ?Object) => void}  onAsked      Holds a new one.
 */

/** Does nothing, as every action does where the page offers none. */
const nothing = () => {};

/** @type {IntelActions} */
const NO_ACTIONS = {
	onOpenEntity: nothing,
	onOpenLens: nothing,
	onOpenWatch: nothing,
	onNewWatch: nothing,
	onSearchFor: nothing,
	onSearch: nothing,
	onSave: undefined,
	canTune: false,
	asked: null,
	onAsked: nothing,
};

/** @type {import('react').Context<IntelPage>} */
export const IntelPageContext = createContext( {
	page: null,
	lens_key: null,
	entity_key: null,
	since: null,
} );

/**
 * Whether the block a control stands in is being edited, as the substrate
 * handed its View `editing`; each block's View provides it (`BLOCK_VIEWS`).
 *
 * @type {import('react').Context<boolean>}
 */
export const Editing = createContext( false );

/**
 * Whether the block this stands in is being edited.
 *
 * @return {boolean} Whether it is.
 */
export const useEditing = () => useContext( Editing );

/**
 * The page's actions, each doing nothing where the page offers none.
 *
 * @return {IntelActions} The actions.
 */
export function useIntelActions() {
	return { ...NO_ACTIONS, ...useContext( IntelPageContext ).actions };
}

/**
 * A write control's props: `onClick` while the layout is read, and while it
 * is edited `aria-disabled` with a click that does nothing, so the control
 * keeps its place and focus.
 *
 * @param {() => void} onClick The write.
 * @return {{onClick: () => void, 'aria-disabled'?: true}} The control's props.
 */
export function useWriteControl( onClick ) {
	return useEditing()
		? { 'aria-disabled': true, onClick: nothing }
		: { onClick };
}
