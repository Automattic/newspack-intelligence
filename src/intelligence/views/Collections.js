import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import Cite from '../components/Cite';
import Refusal from '../components/Refusal';
import { metaLine, utcDay } from '../components/format';

/** The filter showing every item a folder holds. */
const ALL = 'all';

/** The filter showing the items a folder holds that carry no mark. */
const UNUSED = 'unused';

/**
 * The form a curator creates a folder with.
 *
 * @param {Object}                 props
 * @param {(name: string) => void} props.onCreate Sends the name.
 * @return {import('react').ReactElement} The form.
 */
function CreateForm( { onCreate } ) {
	const [ name, setName ] = useState( '' );
	return (
		<form
			className="newspack-intelligence__create"
			{ ...submitProps( () => onCreate( name ) ) }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Name', 'newspack-intelligence' ) }
				value={ name }
				onChange={ setName }
			/>
			<button type="submit" className="button button-primary">
				{ __( 'Create folder', 'newspack-intelligence' ) }
			</button>
		</form>
	);
}

/**
 * The reader's own day, Y-m-d, which a mark is made on unless another is
 * picked.
 *
 * @return {string} Today.
 */
function today() {
	const now = new Date();
	return [
		now.getFullYear(),
		String( now.getMonth() + 1 ).padStart( 2, '0' ),
		String( now.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

/**
 * A saved item's marks with one made, replacing the day of the same mark.
 *
 * @param {Array<{mark: string, day: string}>} marks The marks it carries.
 * @param {string}                             mark  The mark made.
 * @param {string}                             day   Its day.
 * @return {Array<{mark: string, day: string}>} The marks.
 */
function withMark( marks, mark, day ) {
	return [ ...marks.filter( ( one ) => one.mark !== mark ), { mark, day } ];
}

/**
 * Whether a listed item carries every mark a held one does, on its day.
 *
 * @param {Object} listed The item read.
 * @param {Object} held   The item held.
 * @return {boolean} Whether the read reflects the hold.
 */
const reflects = ( listed, held ) =>
	listed.item_key === held.item_key &&
	held.marks.every( ( want ) =>
		listed.marks.some(
			( has ) => has.mark === want.mark && has.day === want.day
		)
	);

/**
 * A saved item's key.
 *
 * @param {Object} item The item.
 * @return {string} Its key.
 */
const itemKey = ( item ) => item.item_key;

/**
 * Whether a saved item shows under a filter.
 *
 * @param {string} show The filter: ALL, UNUSED or a mark.
 * @return {(item: Object) => boolean} The test.
 */
const showing = ( show ) => ( item ) => {
	if ( ALL === show ) {
		return true;
	}
	if ( UNUSED === show ) {
		return 0 === item.marks.length;
	}
	return item.marks.some( ( one ) => one.mark === show );
};

/**
 * One saved item: its title citing its URL, who saved it and when, each
 * mark a badge with its day, and for a curator the controls marking it used
 * and moving it into another folder.
 *
 * @param {Object}                              props
 * @param {Object}                              props.item      The saved item.
 * @param {Object<string, string>}              props.markWords Each mark's word, in order.
 * @param {Object[]}                            props.others    The folders it can move into.
 * @param {boolean}                             props.canTune   Whether to offer the controls.
 * @param {(mark: string, day: string) => void} props.onMark    Marks it used.
 * @param {(into: string) => void}              props.onMove    Moves it.
 * @return {import('react').ReactElement} The row.
 */
function SavedItem( { item, markWords, others, canTune, onMark, onMove } ) {
	const marks = Object.keys( markWords );
	const [ mark, setMark ] = useState( marks[ 0 ] );
	const [ day, setDay ] = useState( today );
	const [ into, setInto ] = useState( '' );
	const target = into || others[ 0 ]?.collection_key || '';
	return (
		<li className="newspack-intelligence__item">
			<div className="newspack-intelligence__item-head">
				<strong>
					<Cite url={ item.url } title={ item.title } />
				</strong>
			</div>
			<p className="newspack-nodes-status is-muted">
				{ metaLine( item.actor, utcDay( item.saved_ts ) ) }
			</p>
			<div className="newspack-intelligence__actions">
				{ 0 === item.marks.length ? (
					<span className="newspack-nodes-status is-muted">
						{ __( 'Not yet used', 'newspack-intelligence' ) }
					</span>
				) : (
					item.marks.map( ( one ) => (
						<span
							key={ one.mark }
							className="newspack-nodes-status-badge is-success is-pill"
						>
							{ sprintf(
								/* translators: 1: where it was used, 2: the day, Y-m-d. */
								__( '%1$s · %2$s', 'newspack-intelligence' ),
								markWords[ one.mark ] ?? one.mark,
								one.day
							) }
						</span>
					) )
				) }
			</div>
			{ canTune && (
				<div className="newspack-intelligence__use">
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Used in', 'newspack-intelligence' ) }
						options={ marks.map( ( one ) => ( {
							label: markWords[ one ],
							value: one,
						} ) ) }
						value={ mark }
						onChange={ setMark }
					/>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'On', 'newspack-intelligence' ) }
						value={ day }
						onChange={ setDay }
					/>
					<button
						type="button"
						className="button"
						onClick={ () => onMark( mark, day ) }
					>
						{ __( 'Mark used', 'newspack-intelligence' ) }
					</button>
					{ 0 < others.length && (
						<>
							<SelectControl
								__next40pxDefaultSize
								label={ __(
									'Move to',
									'newspack-intelligence'
								) }
								options={ others.map( ( one ) => ( {
									label: one.name,
									value: one.collection_key,
								} ) ) }
								value={ target }
								onChange={ setInto }
							/>
							<button
								type="button"
								className="button"
								onClick={ () => onMove( target ) }
							>
								{ __( 'Move', 'newspack-intelligence' ) }
							</button>
						</>
					) }
				</div>
			) }
		</li>
	);
}

/**
 * The filter a folder's items show under: every item, those not yet used,
 * or those carrying one mark.
 *
 * @param {Object}                 props
 * @param {string}                 props.show      The filter.
 * @param {Object<string, string>} props.markWords Each mark's word, in order.
 * @param {(show: string) => void} props.onShow    Picks a filter.
 * @return {import('react').ReactElement} The buttons.
 */
function Filter( { show, markWords, onShow } ) {
	const choices = [
		[ ALL, __( 'All', 'newspack-intelligence' ) ],
		[ UNUSED, __( 'Not yet used', 'newspack-intelligence' ) ],
		...Object.entries( markWords ),
	];
	return (
		<div
			className="newspack-intelligence__actions"
			role="group"
			aria-label={ __( 'Show', 'newspack-intelligence' ) }
		>
			{ choices.map( ( [ value, label ] ) => (
				<button
					key={ value }
					type="button"
					aria-pressed={ show === value }
					className={
						show === value
							? 'button button-small is-active'
							: 'button button-small'
					}
					onClick={ () => onShow( value ) }
				>
					{ label }
				</button>
			) ) }
		</div>
	);
}

/**
 * One folder's saved items as read, those not yet used first, under the
 * filter picked; for a curator each can be marked used or moved, a mark
 * shown and a moved item taken out from the moment it is sent until a read
 * reflects it, since the projector records it seconds after it lands.
 *
 * @param {Object}                 props
 * @param {Object}                 props.collection The listed folder opened.
 * @param {Object[]}               props.folders    Every listed folder.
 * @param {boolean}                props.canTune    Whether to offer the controls.
 * @param {Object<string, string>} props.markWords  Each mark's word, in order.
 * @param {() => void}             props.onMoved    Re-reads the folders once a move lands.
 * @return {import('react').ReactElement} The items.
 */
function Opened( { collection, folders, canTune, markWords, onMoved } ) {
	const key = collection.collection_key;
	const read = useIntelRead( 'collections:one', 'collections', [ key ] );
	const saved = useHeld( read, 'items', itemKey, reflects );
	const [ show, setShow ] = useState( ALL );
	const markUsed = useIntelWrite( 'mark_used', {
		refresh: read.refresh,
		onRefused: saved.refused,
	} );
	const move = useIntelWrite( 'move_item', {
		refresh: () => {
			read.refresh();
			onMoved();
		},
		onRefused: saved.refused,
	} );
	const others = folders.filter(
		( one ) => one.collection_key && one.collection_key !== key
	);
	return (
		<>
			<Refusal error={ markUsed.refusal || move.refusal } />
			<Answer
				title={ collection.name }
				level={ 3 }
				read={ saved.read }
				rows="items"
				empty={ __(
					'This folder holds no item you can read.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => {
					const shown = answer.items.filter( showing( show ) );
					return (
						<>
							<Filter
								show={ show }
								markWords={ markWords }
								onShow={ setShow }
							/>
							{ 0 === shown.length ? (
								<p className="newspack-nodes-empty-state is-quiet">
									{ UNUSED === show
										? __(
												'Every item here has been used.',
												'newspack-intelligence'
										  )
										: __(
												'No item here carries this mark.',
												'newspack-intelligence'
										  ) }
								</p>
							) : (
								<ul className="newspack-intelligence__items">
									{ shown.map( ( item ) => (
										<SavedItem
											key={ item.item_key }
											item={ item }
											markWords={ markWords }
											others={ others }
											canTune={ canTune }
											onMark={ ( mark, day ) =>
												saved.hold(
													item.item_key,
													{
														...item,
														marks: withMark(
															item.marks,
															mark,
															day
														),
													},
													() =>
														markUsed.run( {
															collection: key,
															item: item.item_key,
															mark,
															day,
														} )
												)
											}
											onMove={ ( into ) =>
												saved.hold(
													item.item_key,
													null,
													() =>
														move.run( {
															collection: key,
															item: item.item_key,
															into,
														} )
												)
											}
										/>
									) ) }
								</ul>
							) }
						</>
					);
				} }
			</Answer>
		</>
	);
}

/**
 * The items a curator excluded from every view, the latest first, each with
 * its title citing its URL, who excluded it, its UTC day and why, and the
 * control including it again, which takes it off the list from the moment
 * it is sent until a read no longer lists it; a refusal puts it back.
 *
 * @return {import('react').ReactElement} The list.
 */
function Excluded() {
	const read = useIntelRead( 'collections:excluded', 'excluded', [] );
	const listed = useHeld( read, 'items', itemKey );
	const include = useIntelWrite( 'include_item', {
		refresh: read.refresh,
		onRefused: listed.refused,
	} );
	return (
		<>
			<Refusal error={ include.refusal } />
			<Answer
				title={ __( 'Excluded', 'newspack-intelligence' ) }
				read={ listed.read }
				rows="items"
				empty={ __(
					'No item is excluded from every view.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__items">
						{ answer.items.map( ( item ) => (
							<li
								key={ item.item_key }
								className="newspack-intelligence__item"
							>
								<div className="newspack-intelligence__item-head">
									<strong>
										<Cite
											url={ item.url }
											title={ item.title }
										/>
									</strong>
									<button
										type="button"
										className="button button-small"
										onClick={ () =>
											listed.hold(
												item.item_key,
												null,
												() =>
													include.run( {
														item: item.item_key,
													} )
											)
										}
									>
										{ __(
											'Include again',
											'newspack-intelligence'
										) }
									</button>
								</div>
								<p className="newspack-nodes-status is-muted">
									{ metaLine(
										item.actor,
										utcDay( item.excluded_ts ),
										item.why
									) }
								</p>
							</li>
						) ) }
					</ul>
				) }
			</Answer>
		</>
	);
}

/**
 * A listed collection's key.
 *
 * @param {Object} collection The collection.
 * @return {string} Its key.
 */
const collectionKey = ( collection ) => collection.collection_key;

/**
 * Whether a listed collection is one created by name.
 *
 * @param {Object} listed  The listed collection.
 * @param {Object} created The collection created.
 * @return {boolean} Whether they share a name.
 */
const sameName = ( listed, created ) => listed.name === created.name;

/**
 * Collections: a board of folders, each with its count, those the reader
 * saved or moved into most recently first, as the read orders them; the one
 * opened, with its saved items; and for a curator, the form creating one and
 * the items excluded from every view (`Excluded`), the one place they are
 * listed and included again. A
 * folder created shows, saving and unopened, from the moment it is sent
 * until a read lists it by name, since the projector records it seconds
 * after it lands; a refusal takes it out. One that lands lists again. The
 * page holds the one opened, so it outlives a visit to another view.
 *
 * @param {Object}                       props
 * @param {boolean}                      props.canTune    Whether to offer the create form and the item controls.
 * @param {?Object}                      props.opened     The listed collection opened, or null.
 * @param {(collection: Object) => void} props.onOpen     Opens a listed collection.
 * @param {Object}                       props.vocabulary The ontology's closed lists, `markWords` among them.
 * @return {import('react').ReactElement} The view.
 */
export default function Collections( { canTune, opened, onOpen, vocabulary } ) {
	const list = useIntelRead( 'collections:list', 'collections', [] );
	const listed = useHeld( list, 'collections', collectionKey, sameName );
	const create = useIntelWrite( 'create_collection', {
		refresh: list.refresh,
		onRefused: listed.refused,
	} );

	return (
		<div className="newspack-intelligence__view">
			{ canTune && (
				<CreateForm
					onCreate={ ( name ) =>
						listed.hold(
							name,
							{
								collection_key: null,
								name,
								created_ts: null,
								items: 0,
							},
							() => create.run( { name } )
						)
					}
				/>
			) }
			<Refusal error={ create.refusal } />
			<Answer
				title={ __( 'Folders', 'newspack-intelligence' ) }
				read={ listed.read }
				rows="collections"
				empty={ __( 'No folder yet.', 'newspack-intelligence' ) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__board">
						{ answer.collections.map( ( collection ) =>
							collection.collection_key ? (
								<li
									key={ collection.collection_key }
									className="newspack-intelligence__folder"
								>
									<button
										type="button"
										className={
											opened?.collection_key ===
											collection.collection_key
												? 'button is-active'
												: 'button'
										}
										onClick={ () => onOpen( collection ) }
									>
										{ collection.name }
									</button>
									<span className="newspack-nodes-status is-muted">
										{ metaLine(
											sprintf(
												/* translators: %d: items saved. */
												_n(
													'%d item',
													'%d items',
													collection.items,
													'newspack-intelligence'
												),
												collection.items
											),
											utcDay( collection.created_ts )
										) }
									</span>
								</li>
							) : (
								<li
									key={ collection.name }
									className="newspack-intelligence__folder"
								>
									<span>{ collection.name }</span>
									<span className="newspack-nodes-status is-muted">
										{ __(
											'Saving…',
											'newspack-intelligence'
										) }
									</span>
								</li>
							)
						) }
					</ul>
				) }
			</Answer>
			{ opened && (
				<Opened
					key={ opened.collection_key }
					collection={ opened }
					folders={ listed.read.answer?.collections ?? [] }
					canTune={ canTune }
					markWords={ vocabulary.markWords }
					onMoved={ list.refresh }
				/>
			) }
			{ canTune && <Excluded /> }
		</div>
	);
}
