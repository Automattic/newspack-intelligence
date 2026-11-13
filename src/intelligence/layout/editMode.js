/**
 * Intelligence's composed pages, which the substrate's edit-mode links read:
 * "Edit layout" reloads the page with `edit=<page>`, its view, and for a
 * team view or a profile the subject it was opened from.
 */

/** The namespace every context sits under. */
const NAMESPACE = 'newspack-intelligence';

/**
 * Each composed page => the tab showing it and the param naming its subject;
 * a profile is not a tab, so it stands over whichever view opened it.
 *
 * @type {import('@newspack-nodes/shared/composition/page/editMode').ComposedPages}
 */
export const PAGES = {
	briefing: { view: 'briefing', subject: null },
	lens: { view: 'lenses', subject: 'lens' },
	entity: { view: null, subject: 'entity' },
};

/**
 * The context a page lays out.
 *
 * @param {string} page A composed page.
 * @return {string} Its context.
 */
export const layoutContext = ( page ) => `${ NAMESPACE }/${ page }`;
