import { useCallback, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import {
	commandArgs,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import Cite from '../components/Cite';
import ItemRow from '../components/ItemRow';
import ListField from '../components/ListField';
import Pager from '../components/Pager';
import Refusal from '../components/Refusal';
import {
	draftArgs,
	draftLens,
	lensDraft,
	listOf,
	newLens,
} from '../components/lensDraft';

/** No lens open, its items on their first page. */
export const CLOSED_LENS = { key: null, starts: [ 0 ] };

/**
 * A listed lens's key.
 *
 * @param {Object} lens The lens.
 * @return {string} Its key.
 */
const lensKey = ( lens ) => lens.lens_key;

/**
 * A select's options over a closed list, led by the choice setting none.
 *
 * @param {string[]} values The list.
 * @return {{label: string, value: string}[]} The options.
 */
const choicesOf = ( values ) => [
	{ label: __( 'Not set', 'newspack-intelligence' ), value: '' },
	...values.map( ( value ) => ( { label: value, value } ) ),
];

/**
 * The form a curator saves a lens with, a new one or the one opened: its
 * key, name and description, then every filter — open lists
 * comma-separated, tiers checked, the trend axis and bucket picked. A
 * filter left blank is left out, so the lens does not set it.
 *
 * @param {Object}                                  props
 * @param {Object<string, string>}                  props.initial    The draft it opens on.
 * @param {Object}                                  props.vocabulary The ontology's closed lists.
 * @param {(draft: Object<string, string>) => void} props.onSave     Sends the draft.
 * @param {() => void}                              props.onCancel   Closes the form unsent.
 * @return {import('react').ReactElement} The form.
 */
function LensForm( { initial, vocabulary, onSave, onCancel } ) {
	const [ draft, setDraft ] = useState( initial );
	const field = ( name ) => ( {
		value: draft[ name ],
		onChange: ( value ) => setDraft( { ...draft, [ name ]: value } ),
		__next40pxDefaultSize: true,
	} );
	const tiers = listOf( draft.tier );
	const legend = __( 'Save lens', 'newspack-intelligence' );
	return (
		<form
			className="newspack-intelligence__filters"
			aria-label={ legend }
			{ ...submitProps( () => onSave( draft ) ) }
		>
			<TextControl
				label={ __( 'Key', 'newspack-intelligence' ) }
				placeholder="support-desk"
				{ ...field( 'key' ) }
			/>
			<TextControl
				label={ __( 'Name', 'newspack-intelligence' ) }
				{ ...field( 'name' ) }
			/>
			<TextControl
				label={ __( 'Description', 'newspack-intelligence' ) }
				{ ...field( 'description' ) }
			/>
			<ListField
				label={ __( 'Kinds', 'newspack-intelligence' ) }
				choices={ vocabulary.itemKinds }
				{ ...field( 'kind' ) }
			/>
			<ListField
				label={ __( 'Topics', 'newspack-intelligence' ) }
				placeholder="topic:local-news"
				{ ...field( 'topic' ) }
			/>
			<ListField
				label={ __( 'Roles', 'newspack-intelligence' ) }
				choices={ vocabulary.roles }
				{ ...field( 'role' ) }
			/>
			<ListField
				label={ __( 'Sources', 'newspack-intelligence' ) }
				placeholder="github, linear"
				{ ...field( 'source' ) }
			/>
			<TextControl
				label={ __( 'Entity', 'newspack-intelligence' ) }
				placeholder="organization:acme-gazette"
				{ ...field( 'entity' ) }
			/>
			<TextControl
				type="number"
				min={ 1 }
				label={ __( 'Days', 'newspack-intelligence' ) }
				help={ __(
					'The last this many days.',
					'newspack-intelligence'
				) }
				{ ...field( 'days' ) }
			/>
			<fieldset className="newspack-intelligence__choices">
				<legend>{ __( 'Tiers', 'newspack-intelligence' ) }</legend>
				{ vocabulary.tiers.map( ( tier ) => (
					<CheckboxControl
						key={ tier }
						label={ tier }
						checked={ tiers.includes( tier ) }
						onChange={ ( on ) =>
							setDraft( {
								...draft,
								tier: vocabulary.tiers
									.filter( ( one ) =>
										one === tier
											? on
											: tiers.includes( one )
									)
									.join( ', ' ),
							} )
						}
					/>
				) ) }
			</fieldset>
			<SelectControl
				label={ __( 'Count by', 'newspack-intelligence' ) }
				options={ choicesOf( vocabulary.trendAxes ) }
				{ ...field( 'by' ) }
			/>
			<SelectControl
				label={ __( 'Bucket', 'newspack-intelligence' ) }
				options={ choicesOf( vocabulary.buckets ) }
				{ ...field( 'bucket' ) }
			/>
			<div className="newspack-intelligence__actions">
				<button type="submit" className="button button-primary">
					{ legend }
				</button>
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
			</div>
		</form>
	);
}

/**
 * A lens's trend rows: each bucket and value with its count, citing the
 * newest items the row counts.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.rows The rows `trends` answers.
 * @return {import('react').ReactElement} The table.
 */
function TrendTable( { rows } ) {
	return (
		<table className="newspack-nodes-table">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'Bucket', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Value', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Count', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Items', 'newspack-intelligence' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ `${ row.bucket } ${ row.value }` }>
						<td>{ row.bucket }</td>
						<td>{ row.value }</td>
						<td>{ row.count }</td>
						<td>
							<ul className="newspack-intelligence__facts">
								{ row.items.map( ( item ) => (
									<li key={ item.item_key }>
										<Cite
											url={ item.url }
											title={ item.title }
										/>
									</li>
								) ) }
							</ul>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The lens opened: its items a page at a time beside its trend rows, each
 * read through the lens.
 *
 * @param {Object}                     props
 * @param {Object}                     props.lens         The listed lens opened.
 * @param {number[]}                   props.starts       Each page's first row.
 * @param {(starts: number[]) => void} props.onStarts     Replaces the page starts.
 * @param {(key: string) => void}      props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}     [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The lens's reads.
 */
function Opened( { lens, starts, onStarts, onOpenEntity, onSave } ) {
	const through = { lens: lens.lens_key };
	const offset = starts[ starts.length - 1 ];
	const items = useIntelRead(
		'lens:items',
		'search',
		commandArgs( 0 < offset ? { ...through, offset } : through )
	);
	const trends = useIntelRead(
		'lens:trends',
		'trends',
		commandArgs( through )
	);
	return (
		<div className="newspack-intelligence__lens">
			<Answer
				title={ lens.name }
				level={ 3 }
				read={ items }
				rows="items"
				empty={ __(
					'No readable item is seen through this lens.',
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
							onStarts={ onStarts }
							shown={ answer.items.length }
							total={ answer.total }
						/>
					</>
				) }
			</Answer>
			<Answer
				title={ __( 'Trends', 'newspack-intelligence' ) }
				level={ 3 }
				read={ trends }
				rows="rows"
				empty={ __(
					'No trend is seen through this lens.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => <TrendTable rows={ answer.rows } /> }
			</Answer>
		</div>
	);
}

/**
 * Lenses: every lens, the one opened with its items and trend rows read
 * through it, and for a curator the form saving a new lens or the one
 * opened, and the control removing it. A save shows its lens and a removal
 * takes its lens out from the moment each is sent, until a read of `lenses`
 * reflects it, since the projector records it seconds after it lands; a
 * refusal puts the lens back. One that lands lists again, and a removal
 * also closes the lens. The page holds the lens opened and its page, so it
 * outlives a visit to another view.
 *
 * @param {Object}                                             props
 * @param {boolean}                                            props.canTune      Whether to offer curation.
 * @param {Object}                                             props.vocabulary   The ontology's closed lists.
 * @param {{key: ?string, starts: number[]}}                   props.opened       The lens opened and its page starts.
 * @param {(opened: {key: ?string, starts: number[]}) => void} props.onOpen       Opens a lens, or a page of it.
 * @param {(key: string) => void}                              props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                             [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Lens( {
	canTune,
	vocabulary,
	opened,
	onOpen,
	onOpenEntity,
	onSave,
} ) {
	const lists = vocabulary.lensLists;
	const [ editing, setEditing ] = useState( null );
	const list = useIntelRead( 'lens:list', 'lenses', [] );
	// The server slugs a new key, so a save is reflected by all but its key.
	const matches = useCallback(
		( listed, held ) => {
			const { key: k1, ...one } = lensDraft( listed, lists );
			const { key: k2, ...two } = lensDraft( held, lists );
			return JSON.stringify( one ) === JSON.stringify( two );
		},
		[ lists ]
	);
	const lenses = useHeld( list, 'lenses', lensKey, matches );
	const save = useIntelWrite( 'save_lens', {
		refresh: () => {
			setEditing( null );
			list.refresh();
		},
		onRefused: lenses.refused,
	} );
	const remove = useIntelWrite( 'remove_lens', {
		refresh: () => {
			onOpen( CLOSED_LENS );
			list.refresh();
		},
		onRefused: lenses.refused,
	} );
	const lens = lenses.read.answer?.lenses.find(
		( one ) => one.lens_key === opened.key
	);

	return (
		<div className="newspack-intelligence__view">
			{ canTune &&
				( editing ? (
					<LensForm
						initial={ editing }
						vocabulary={ vocabulary }
						onSave={ ( draft ) =>
							lenses.hold(
								draft.key,
								draftLens( draft, lists ),
								() => save.run( draftArgs( draft, lists ) )
							)
						}
						onCancel={ () => setEditing( null ) }
					/>
				) : (
					<div className="newspack-intelligence__create">
						<button
							type="button"
							className="button button-primary"
							onClick={ () => setEditing( newLens( lists ) ) }
						>
							{ __( 'New lens', 'newspack-intelligence' ) }
						</button>
						{ lens && (
							<>
								<button
									type="button"
									className="button"
									onClick={ () =>
										setEditing( lensDraft( lens, lists ) )
									}
								>
									{ sprintf(
										/* translators: %s: the lens's name. */
										__(
											'Edit %s',
											'newspack-intelligence'
										),
										lens.name
									) }
								</button>
								<button
									type="button"
									className="button"
									onClick={ () =>
										lenses.hold( lens.lens_key, null, () =>
											remove.run( { key: lens.lens_key } )
										)
									}
								>
									{ sprintf(
										/* translators: %s: the lens's name. */
										__(
											'Remove %s',
											'newspack-intelligence'
										),
										lens.name
									) }
								</button>
							</>
						) }
					</div>
				) ) }
			<Refusal error={ save.refusal } />
			<Refusal error={ remove.refusal } />
			<Answer
				title={ __( 'Lenses', 'newspack-intelligence' ) }
				read={ lenses.read }
				rows="lenses"
				empty={ __( 'No lens yet.', 'newspack-intelligence' ) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.lenses.map( ( one ) => (
							<li key={ one.lens_key }>
								<button
									type="button"
									className={
										opened.key === one.lens_key
											? 'button button-small is-active'
											: 'button button-small'
									}
									onClick={ () =>
										onOpen( {
											key: one.lens_key,
											starts: [ 0 ],
										} )
									}
								>
									{ one.name }
								</button>{ ' ' }
								<span className="newspack-nodes-status is-muted">
									{ one.description }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</Answer>
			{ lens && (
				<Opened
					key={ JSON.stringify( lens.filters ) }
					lens={ lens }
					starts={ opened.starts }
					onStarts={ ( starts ) =>
						onOpen( { key: lens.lens_key, starts } )
					}
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
				/>
			) }
		</div>
	);
}
