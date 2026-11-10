/**
 * Which layout a link edits. "Edit layout" reloads the page with `edit=<page>`,
 * its view, and for a team view or a profile the subject it was opened from,
 * because only a load naming the edit, for a `tune` caller, loads the block
 * editor.
 */

import { setQueryParams } from '@newspack-nodes/shared/utils/queryParams';

/** The namespace every context sits under. */
const NAMESPACE = 'newspack-intelligence';

/** @typedef {'briefing'|'lens'|'entity'} ComposedPage */

/**
 * Each composed page => the tab showing it and the param naming its subject;
 * a profile is not a tab, so it stands over whichever view opened it.
 */
const PAGES = {
	briefing: { view: 'briefing', subject: null },
	lens: { view: 'lenses', subject: 'lens' },
	entity: { view: null, subject: 'entity' },
};

/** Every composed page. */
const COMPOSED = /** @type {ComposedPage[]} */ ( Object.keys( PAGES ) );

/** Every param naming a page's subject. */
const SUBJECTS = Object.values( PAGES )
	.map( ( { subject } ) => subject )
	.filter( ( subject ) => null !== subject );

/**
 * The layout a page edits.
 *
 * @typedef {Object} EditTarget
 * @property {ComposedPage} page         The page.
 * @property {string}       [lens_key]   The team view it was opened from.
 * @property {string}       [entity_key] The profile it was opened from.
 */

/**
 * The context a page lays out.
 *
 * @param {string} page A composed page.
 * @return {string} Its context.
 */
export const layoutContext = ( page ) => `${ NAMESPACE }/${ page }`;

/**
 * The layout a query string edits, or null where it names none.
 *
 * @param {string} search A query string.
 * @return {?EditTarget} The page and its subject.
 */
export function editTarget( search ) {
	const query = new URLSearchParams( search );
	const page = COMPOSED.find( ( one ) => one === query.get( 'edit' ) );
	if ( undefined === page ) {
		return null;
	}
	const param = PAGES[ page ].subject;
	const subject = null === param ? '' : query.get( param ) ?? '';
	return '' === subject ? { page } : { page, [ `${ param }_key` ]: subject };
}

/**
 * A query string editing `target` on its tab, or for a profile on the view
 * it stands over, every other param kept.
 *
 * @param {string}     search The page's query string.
 * @param {EditTarget} target The layout to edit.
 * @return {string} The query string, `?` first.
 */
export function editSearch( search, target ) {
	const query = new URLSearchParams( search );
	SUBJECTS.forEach( ( param ) => query.delete( param ) );
	const { view, subject } = PAGES[ target.page ];
	if ( null !== view ) {
		query.set( 'view', view );
	}
	query.set( 'edit', target.page );
	if ( null !== subject && target[ `${ subject }_key` ] ) {
		query.set( subject, target[ `${ subject }_key` ] );
	}
	return `?${ query.toString() }`;
}

/** Drop the edit and its subject from the page's URL, so a reload reads. */
export function leaveEdit() {
	setQueryParams( {
		edit: null,
		...Object.fromEntries( SUBJECTS.map( ( param ) => [ param, null ] ) ),
	} );
}
