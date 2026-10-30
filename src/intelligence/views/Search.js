import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import ItemRow from '../components/ItemRow';
import ListField from '../components/ListField';
import Pager from '../components/Pager';
import Refusal from '../components/Refusal';
import { daysSince, draftArgs, lensDraft } from '../components/lensDraft';

/**
 * What each list filter is called, by option.
 *
 * @return {Object<string, string>} The labels.
 */
const listLabels = () => ( {
	kind: __( 'Kinds', 'newspack-intelligence' ),
	topic: __( 'Topics', 'newspack-intelligence' ),
	role: __( 'Roles', 'newspack-intelligence' ),
	source: __( 'Sources', 'newspack-intelligence' ),
} );

/**
 * A search before anything is asked: no filter, on its first page.
 *
 * @param {Array<Object>} lists The lens's list filters.
 * @return {{filters: Object<string, string>, starts: number[]}} The query.
 */
export function newSearch( lists ) {
	return {
		filters: Object.fromEntries(
			[
				'q',
				...lists.map( ( { option } ) => option ),
				'entity',
				'since',
				'until',
				'lens',
			].map( ( name ) => [ name, '' ] )
		),
		starts: [ 0 ],
	};
}

/**
 * Every filter a lens searches with, read-only: each list, its entity, its
 * rolling days and its tiers, a filter it leaves unset reading as any; and,
 * when it names tiers, that editing it as one's own search drops them.
 *
 * @param {Object}        props
 * @param {Object}        props.lens  The lens picked.
 * @param {Array<Object>} props.lists The lens's list filters.
 * @return {import('react').ReactElement} The filters.
 */
function LensRuns( { lens, lists } ) {
	const text = lensDraft( lens, lists );
	const labels = listLabels();
	const days = Number( text.days );
	const rows = [
		...lists.map( ( { option } ) => [ labels[ option ], text[ option ] ] ),
		[ __( 'Entity', 'newspack-intelligence' ), text.entity ],
		[
			__( 'Days', 'newspack-intelligence' ),
			'' === text.days
				? ''
				: sprintf(
						/* translators: %d: how many days the lens reads. */
						_n(
							'The last %d day',
							'The last %d days',
							days,
							'newspack-intelligence'
						),
						days
				  ),
		],
		[ __( 'Tiers', 'newspack-intelligence' ), text.tier ],
	];
	return (
		<div
			className="newspack-intelligence__runs"
			role="group"
			aria-label={ sprintf(
				/* translators: %s: the lens's name. */
				__( '%s searches with', 'newspack-intelligence' ),
				lens.name
			) }
		>
			<dl>
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label }>
						<dt className="newspack-nodes-status is-muted">
							{ label }
						</dt>
						<dd>
							{ value || __( 'Any', 'newspack-intelligence' ) }
						</dd>
					</div>
				) ) }
			</dl>
			{ '' !== text.tier && (
				<p className="newspack-nodes-status is-muted">
					{ __(
						'A search reads every tier you may read, so editing drops this lens’s tiers.',
						'newspack-intelligence'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Search: the newest readable items matching every filter given, a page at a
 * time, each with its linked entities as chips. A lens picked shows every
 * filter it searches with, read-only, and sends only the lens, the words and
 * the page; "Edit as my own search" copies its filters into Search's own
 * fields, its days as the day they start from and without its tiers, and
 * drops the lens. The page holds the submitted search, so it outlives a
 * visit to another view.
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
	const lists = vocabulary.lensLists;
	const { filters, starts } = query;
	const [ draft, setDraft ] = useState( filters );
	const setStarts = ( next ) => onQuery( { filters, starts: next } );
	const offset = starts[ starts.length - 1 ];
	const read = useIntelRead(
		'search:items',
		'search',
		commandArgs( {
			...( filters.lens
				? { q: filters.q, lens: filters.lens }
				: draftArgs( filters, lists ) ),
			...( 0 < offset ? { offset } : {} ),
		} )
	);
	const lenses = useIntelRead( 'search:lenses', 'lenses', [] );
	const lens = lenses.answer?.lenses.find(
		( one ) => one.lens_key === draft.lens
	);
	const submit = ( next ) => {
		setDraft( next );
		onQuery( { filters: next, starts: [ 0 ] } );
	};
	const editAsOwn = () => {
		const text = lensDraft( lens, lists );
		submit( {
			...newSearch( lists ).filters,
			q: draft.q,
			...Object.fromEntries(
				lists.map( ( { option } ) => [ option, text[ option ] ] )
			),
			entity: text.entity,
			since:
				'' === text.days
					? ''
					: daysSince( Number( text.days ), Date.now() ),
		} );
	};

	const labels = listLabels();
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
				{ ...submitProps( () => submit( draft ) ) }
			>
				<SelectControl
					label={ __( 'Lens', 'newspack-intelligence' ) }
					options={ [
						{
							label: __( 'No lens', 'newspack-intelligence' ),
							value: '',
						},
						...( lenses.answer?.lenses ?? [] ).map( ( one ) => ( {
							label: one.name,
							value: one.lens_key,
						} ) ),
					] }
					{ ...field( 'lens' ) }
					onChange={ ( key ) => submit( { ...draft, lens: key } ) }
				/>
				<TextControl
					label={ __( 'Words', 'newspack-intelligence' ) }
					{ ...field( 'q' ) }
				/>
				{ lens ? (
					<LensRuns lens={ lens } lists={ lists } />
				) : (
					<>
						{ lists.map( ( { option, values } ) => (
							<ListField
								key={ option }
								label={ labels[ option ] }
								choices={ values }
								{ ...field( option ) }
							/>
						) ) }
						<TextControl
							label={ __( 'Entity', 'newspack-intelligence' ) }
							placeholder="organization:acme-gazette"
							{ ...field( 'entity' ) }
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
					</>
				) }
				<div className="newspack-intelligence__actions">
					<button type="submit" className="button button-primary">
						{ __( 'Search', 'newspack-intelligence' ) }
					</button>
					{ lens && (
						<button
							type="button"
							className="button"
							onClick={ editAsOwn }
						>
							{ __(
								'Edit as my own search',
								'newspack-intelligence'
							) }
						</button>
					) }
				</div>
			</form>
			<Refusal error={ lenses.error } />
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
