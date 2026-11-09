import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import Answer, { Unread } from '../components/Answer';
import AskBox from '../components/AskBox';
import { ItemPage } from '../components/ItemRow';
import EntityPicker, { useEntityNames } from '../components/EntityPicker';
import ListField from '../components/ListField';
import ListPicker from '../components/ListPicker';
import {
	daysSince,
	draftArgs,
	lensDraft,
	listOf,
} from '../components/lensDraft';
import { word } from '../components/words';

/** The list filters picked as tokens, by option, each by the map wording it; any other is typed. */
const PICKED = { kind: 'kindWords', role: 'roleWords', source: 'sourceWords' };

/**
 * What each list filter is called, by option.
 *
 * @return {Object<string, string>} The labels.
 */
const listLabels = () => ( {
	kind: __( 'Kind of news', 'newspack-intelligence' ),
	topic: __( 'Topics', 'newspack-intelligence' ),
	role: __( 'About', 'newspack-intelligence' ),
	source: __( 'Sources', 'newspack-intelligence' ),
} );

/**
 * A list filter's comma-separated values as shown: each picked one by its
 * word, a typed one as given.
 *
 * @param {string} option The filter.
 * @param {string} text   Its values, comma-separated.
 * @return {string} The values shown.
 */
const shownList = ( option, text ) =>
	PICKED[ option ]
		? listOf( text )
				.map( ( one ) => word( PICKED[ option ], one ) )
				.join( ', ' )
		: text;

/**
 * A search before anything is asked: no filter, on its first page, in the
 * order the server picks.
 *
 * @param {Array<Object>} lists The lens's list filters.
 * @return {{filters: Object<string, string>, starts: number[], sort: string}} The query.
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
		sort: '',
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
		...lists.map( ( { option } ) => [
			labels[ option ],
			shownList( option, text[ option ] ),
		] ),
		[ __( 'Client or company', 'newspack-intelligence' ), text.entity ],
		[
			__( 'Look back', 'newspack-intelligence' ),
			'' === text.days
				? ''
				: sprintf(
						/* translators: %d: how many days the team view reads. */
						_n(
							'%d day',
							'%d days',
							days,
							'newspack-intelligence'
						),
						days
				  ),
		],
		[
			__( 'Include items that are', 'newspack-intelligence' ),
			listOf( text.tier )
				.map( ( tier ) => word( 'tierWords', tier ) )
				.join( ', ' ),
		],
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
						'A search reads every tier you may read, so editing drops this team view’s tiers.',
						'newspack-intelligence'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Search: the readable items matching every filter given, a page at a time,
 * in the order the answer names, which the reader may switch, each with its
 * linked entities as chips. Kinds, roles and sources are multi-selects, a
 * closed list taking only its own values, and the entity suggests the
 * entities whose names its text leads, a name picked searching its key. A
 * lens picked shows every
 * filter it searches with, read-only, and sends only the lens, the words,
 * the order and the page; "Edit as my own search" copies its filters into
 * Search's own fields, its days as the day they start from and without its
 * tiers, and drops the lens. The page holds the submitted search and its
 * order, so they outlive a visit to another view.
 *
 * @param {Object}                   props
 * @param {Object}                   props.operator     Who runs the deployment, as the page is localized it.
 * @param {Object}                   props.query        The submitted filters, and each page's start.
 * @param {(query: Object) => void}  props.onQuery      Replaces the query.
 * @param {Object}                   props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}    props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}   [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                  [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @param {?Object}                  props.asked        The ask the page holds, which its AskBox shows.
 * @param {(asked: ?Object) => void} props.onAsked      Holds a new one.
 * @return {import('react').ReactElement} The view.
 */
export default function Search( {
	operator,
	query,
	onQuery,
	vocabulary,
	onOpenEntity,
	onSave,
	canTune,
	asked,
	onAsked,
} ) {
	const lists = vocabulary.lensLists;
	const { filters, starts, sort } = query;
	const [ draft, setDraft ] = useState( filters );
	const setStarts = ( next ) => onQuery( { ...query, starts: next } );
	const offset = starts[ starts.length - 1 ];
	const read = useIntelRead(
		'search:items',
		'search',
		commandArgs( {
			...( filters.lens
				? { q: filters.q, lens: filters.lens }
				: draftArgs( filters, lists ) ),
			sort,
			...( 0 < offset ? { offset } : {} ),
		} )
	);
	const lenses = useIntelRead( 'search:lenses', 'lenses', [] );
	const names = useEntityNames( draft.entity );
	const lens = lenses.answer?.lenses.find(
		( one ) => one.lens_key === draft.lens
	);
	const submit = ( next ) => {
		setDraft( next );
		onQuery( { ...query, filters: next, starts: [ 0 ] } );
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
			<AskBox
				operator={ operator }
				asked={ asked }
				onAsked={ onAsked }
				onOpenEntity={ onOpenEntity }
				onSave={ onSave }
				canTune={ canTune }
			/>
			<form
				className="newspack-intelligence__filters"
				role="search"
				aria-label={ __( 'Filters', 'newspack-intelligence' ) }
				{ ...submitProps( () =>
					submit( { ...draft, entity: names.keyOf( draft.entity ) } )
				) }
			>
				<SelectControl
					label={ __( 'Team view', 'newspack-intelligence' ) }
					options={ [
						{
							label: __(
								'No team view',
								'newspack-intelligence'
							),
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
						{ lists.map( ( { option, values } ) =>
							PICKED[ option ] ? (
								<ListPicker
									key={ option }
									label={ labels[ option ] }
									map={ PICKED[ option ] }
									values={ values }
									{ ...field( option ) }
								/>
							) : (
								<ListField
									key={ option }
									label={ labels[ option ] }
									choices={ values }
									{ ...field( option ) }
								/>
							)
						) }
						<EntityPicker
							label={ __(
								'Client or company',
								'newspack-intelligence'
							) }
							value={ draft.entity }
							onChange={ field( 'entity' ).onChange }
							names={ names }
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
			<Unread error={ lenses.error } refresh={ lenses.refresh } />
			<Answer
				read={ read }
				rows="items"
				empty={ __(
					'Nothing matches these filters.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ItemPage
						answer={ answer }
						starts={ starts }
						onStarts={ setStarts }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
						onSort={ ( next ) =>
							onQuery( { ...query, sort: next, starts: [ 0 ] } )
						}
					/>
				) }
			</Answer>
		</div>
	);
}
