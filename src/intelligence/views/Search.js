import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useIntelRead } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import ItemRow from '../components/ItemRow';
import Pager from '../components/Pager';

/** The filters `search` takes, each sent as `--<name>=<value>` when given. */
const FILTERS = [ 'q', 'kind', 'topic', 'source', 'since', 'until' ];

/** A search before anything is asked: no filter, on its first page. */
export const NEW_SEARCH = {
	filters: Object.fromEntries( FILTERS.map( ( name ) => [ name, '' ] ) ),
	starts: [ 0 ],
};

/**
 * The tokens one search sends: each filter given, then the page start.
 *
 * @param {Object} filters The submitted filters.
 * @param {number} offset  Rows skipped before the page.
 * @return {string[]} The argument tokens.
 */
function searchArgs( filters, offset ) {
	const given = Object.fromEntries(
		FILTERS.filter( ( name ) => '' !== filters[ name ] ).map( ( name ) => [
			name,
			filters[ name ],
		] )
	);
	return formatCommandArgs( [], 0 < offset ? { ...given, offset } : given );
}

/**
 * Search: the newest readable items matching every filter given, a page at a
 * time, each with its linked entities as chips. The page holds the submitted
 * search, so it outlives a visit to another view.
 *
 * @param {Object}                  props
 * @param {Object}                  props.query        The submitted filters, and each page's start.
 * @param {(query: Object) => void} props.onQuery      Replaces the query.
 * @param {Object}                  props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}   props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}  [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Search( {
	query,
	onQuery,
	vocabulary,
	onOpenEntity,
	onSave,
} ) {
	const { filters, starts } = query;
	const [ draft, setDraft ] = useState( filters );
	const setStarts = ( next ) => onQuery( { filters, starts: next } );
	const offset = starts[ starts.length - 1 ];
	const read = useIntelRead(
		'search:items',
		'search',
		searchArgs( filters, offset )
	);

	const field = ( name ) => ( {
		value: draft[ name ],
		onChange: ( value ) => setDraft( { ...draft, [ name ]: value } ),
		__next40pxDefaultSize: true,
	} );

	return (
		<div className="newspack-intelligence__view">
			<form
				className="newspack-intelligence__filters"
				role="search"
				aria-label={ __( 'Filters', 'newspack-intelligence' ) }
				{ ...submitProps( () =>
					onQuery( { filters: draft, starts: [ 0 ] } )
				) }
			>
				<TextControl
					label={ __( 'Words', 'newspack-intelligence' ) }
					{ ...field( 'q' ) }
				/>
				<SelectControl
					label={ __( 'Kind', 'newspack-intelligence' ) }
					options={ [
						{
							label: __( 'Any kind', 'newspack-intelligence' ),
							value: '',
						},
						...vocabulary.itemKinds.map( ( kind ) => ( {
							label: kind,
							value: kind,
						} ) ),
					] }
					{ ...field( 'kind' ) }
				/>
				<TextControl
					label={ __( 'Topic', 'newspack-intelligence' ) }
					placeholder="topic:local-news"
					{ ...field( 'topic' ) }
				/>
				<TextControl
					label={ __( 'Source', 'newspack-intelligence' ) }
					placeholder="github"
					{ ...field( 'source' ) }
				/>
				<TextControl
					type="date"
					label={ __( 'Since', 'newspack-intelligence' ) }
					{ ...field( 'since' ) }
				/>
				<TextControl
					type="date"
					label={ __( 'Until', 'newspack-intelligence' ) }
					{ ...field( 'until' ) }
				/>
				<button type="submit" className="button button-primary">
					{ __( 'Search', 'newspack-intelligence' ) }
				</button>
			</form>
			<Answer
				read={ read }
				rows="items"
				empty={ __(
					'No readable item matches these filters.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<>
						<ul className="newspack-intelligence__items">
							{ answer.items.map( ( item ) => (
								<ItemRow
									key={ item.item_key }
									item={ item }
									onOpenEntity={ onOpenEntity }
									onSave={ onSave }
								/>
							) ) }
						</ul>
						<Pager
							starts={ starts }
							onStarts={ setStarts }
							shown={ answer.items.length }
							total={ answer.total }
						/>
					</>
				) }
			</Answer>
		</div>
	);
}
