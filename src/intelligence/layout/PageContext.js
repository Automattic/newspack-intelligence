/**
 * What a block on a composed page reads besides its attributes: the page,
 * its subject, the reader's last look and the page's actions a block's rows
 * open. The page stands in the substrate's `ComposedPage`, read through
 * `usePage()`.
 */

import { usePage } from '@newspack-nodes/shared/composition/page';

/**
 * The page a block stands on, as the substrate's frame carries it.
 *
 * @typedef {Object} IntelPage
 * @property {?('briefing'|'lens'|'entity')} page       The page.
 * @property {?string}                       lens_key   The team view it shows, on a team view's page.
 * @property {?string}                       entity_key The entity it profiles, on a profile.
 * @property {?number}                       since      The reader's last look, UTC seconds, on the Briefing.
 * @property {boolean}                       [kept]     Whether the browser keeps the last look; false hides what arrived since.
 * @property {Partial<IntelActions>}         [actions]  The page's actions; the editor's preview offers none.
 * @property {string}                        group      The surface the page's commands are sent for.
 * @property {boolean}                       canTune    Whether the reader may curate the layout.
 * @property {(layout: Object) => void}      onRestored Takes the default a curator's restore answers.
 * @property {boolean}                       [operator] Whether the reader may read why a layout was refused.
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

/**
 * The page a block stands on.
 *
 * @return {IntelPage} The page.
 */
export const useIntelPage = () => /** @type {IntelPage} */ ( usePage() );

/**
 * The page's actions, each doing nothing where the page offers none.
 *
 * @return {IntelActions} The actions.
 */
export function useIntelActions() {
	return { ...NO_ACTIONS, ...useIntelPage().actions };
}
