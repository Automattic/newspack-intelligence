import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	FormTokenField,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import AskBox from '../components/AskBox';
import { ItemPage } from '../components/ItemRow';
import EntityPicker, { useEntityNames } from '../components/EntityPicker';
import ListField from '../components/ListField';
import Refusal from '../components/Refusal';
import {
	daysSince,
	draftArgs,
	lensDraft,
	listOf,
} from '../components/lensDraft';

/** The list filters picked as tokens, by option; any other is typed. */
const PICKED = new Set( [ 'kind', 'role', 'source' ] );

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
 * @param {Object}                   props.query        The submitted filters, and each page's start.
 * @param {(query: Object) => void}  props.onQuery      Replaces the query.
 * @param {Object}                   props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}    props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}   [props.onSave]     Saves an item; absent for a reader.
 * @param {?Object}                  props.asked        The ask the page holds, which its AskBox shows.
 * @param {(asked: ?Object) => void} props.onAsked      Holds a new one.
 * @return {import('react').ReactElement} The view.
 */
export default function Search( {
	query,
	onQuery,
	vocabulary,
	onOpenEntity,
	onSave,
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
	const picker = ( name, values ) => {
		const allowed = ( token ) => ! values || values.includes( token );
		return (
			<FormTokenField
				key={ name }
				label={ labels[ name ] }
				value={ listOf( draft[ name ] ) }
				suggestions={ values ?? [] }
				__experimentalValidateInput={ allowed }
				__experimentalExpandOnFocus={ !! values }
				tokenizeOnBlur
				// A token typed with a comma skips the field's own validation.
				onChange={ ( picked ) =>
					setDraft( {
						...draft,
						[ name ]: picked.filter( allowed ).join( ', ' ),
					} )
				}
				__next40pxDefaultSize
			/>
		);
	};

	return (
		<div className="newspack-intelligence__view">
			<AskBox
				asked={ asked }
				onAsked={ onAsked }
				onOpenEntity={ onOpenEntity }
				onSave={ onSave }
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
						{ lists.map( ( { option, values } ) =>
							PICKED.has( option ) ? (
								picker( option, values )
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
							label={ __( 'Entity', 'newspack-intelligence' ) }
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
					<ItemPage
						answer={ answer }
						starts={ starts }
						onStarts={ setStarts }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						onSort={ ( next ) =>
							onQuery( { ...query, sort: next, starts: [ 0 ] } )
						}
					/>
				) }
			</Answer>
		</div>
	);
}
