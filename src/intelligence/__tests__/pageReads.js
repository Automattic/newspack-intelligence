/* eslint-env jest */
/**
 * The page's shared reads around what a suite renders, as the Intelligence
 * page provides them to every view and block beneath it.
 */

import { render } from '@testing-library/react';
import { PageReads, usePageReads } from '../hooks/usePageRead';

/**
 * @param {Object}                    props
 * @param {boolean}                   [props.review] Whether the page reads the review queue.
 * @param {import('react').ReactNode} props.children What stands on the page.
 * @return {import('react').ReactElement} The page.
 */
export function OnPage( { review = false, children } ) {
	return (
		<PageReads.Provider value={ usePageReads( { review } ) }>
			{ children }
		</PageReads.Provider>
	);
}

/**
 * Render `ui` on a page providing its shared reads.
 *
 * @param {import('react').ReactElement} ui               What to render.
 * @param {Object}                       [options]        What `render` takes, and:
 * @param {boolean}                      [options.review] Whether the page reads the review queue.
 * @return {import('@testing-library/react').RenderResult} The render, whose
 *   `rerender` keeps the page around what it is handed.
 */
export function renderOnPage( ui, { review = false, ...options } = {} ) {
	const page = ( node ) => <OnPage review={ review }>{ node }</OnPage>;
	const view = render( page( ui ), options );
	return { ...view, rerender: ( node ) => view.rerender( page( node ) ) };
}
