import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Card,
	CardBody,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { moreHorizontal } from '@wordpress/icons';
import { Core } from '@newspack-nodes/runtime';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import Cite from './Cite';
import Refusal from './Refusal';
import { dayLabel, safeHref, utcDay } from './format';
import { words } from './words';

/**
 * The reader's own day, Y-m-d, which a mark is made on unless another is
 * picked.
 *
 * @return {string} Today.
 */
function localToday() {
	const now = new Date();
	return [
		now.getFullYear(),
		String( now.getMonth() + 1 ).padStart( 2, '0' ),
		String( now.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

/**
 * A use mark as its badge's words: the place's word and the day, "Newsletter,
 * 5 Nov".
 *
 * @param {{mark: string, day: string}} use   The mark.
 * @param {string}                      today Today's UTC day, Y-m-d.
 * @return {string} The words.
 */
function markText( use, today ) {
	return sprintf(
		/* translators: 1: where it was used, 2: the day, as "5 Nov". */
		__( '%1$s, %2$s', 'newspack-intelligence' ),
		words( 'markWords' )[ use.mark ] ?? use.mark,
		dayLabel( use.day, today )
	);
}

/**
 * The dialog marking an item used: "Where" as a radio per place, "On" a
 * date, today by default.
 *
 * @param {Object}                              props
 * @param {string}                              props.mark    The place picked first.
 * @param {(mark: string, day: string) => void} props.onMark  Marks it.
 * @param {() => void}                          props.onClose Dismisses it.
 * @return {import('react').ReactElement} The dialog.
 */
function MarkDialog( { mark, onMark, onClose } ) {
	const [ where, setWhere ] = useState( mark );
	const [ day, setDay ] = useState( localToday );
	return (
		<Modal onClose={ onClose } onSubmit={ () => onMark( where, day ) }>
			<ModalHeader
				title={ __( 'Mark as used', 'newspack-intelligence' ) }
				onClose={ onClose }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<RadioControl
				label={ __( 'Where', 'newspack-intelligence' ) }
				selected={ where }
				options={ Object.entries( words( 'markWords' ) ).map(
					( [ value, label ] ) => ( { label, value } )
				) }
				onChange={ setWhere }
			/>
			<TextControl
				__next40pxDefaultSize
				type="date"
				label={ __( 'On', 'newspack-intelligence' ) }
				value={ day }
				onChange={ setDay }
			/>
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button type="submit" className="button button-primary">
					{ __( 'Mark as used', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

/**
 * A card's menu: mark it used in a place, which opens the dialog on that
 * place; move it into another folder in one action; open its source; and
 * remove it from the folder.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item     The saved item.
 * @param {Object[]}               props.others   The folders it can move into.
 * @param {(mark: string) => void} props.onUse    Opens the dialog on a place.
 * @param {(into: string) => void} props.onMove   Moves it.
 * @param {() => void}             props.onRemove Removes it from the folder.
 * @return {import('react').ReactElement} The menu.
 */
function CardMenu( { item, others, onUse, onMove, onRemove } ) {
	const href = safeHref( item.url );
	return (
		<DropdownMenu
			icon={ moreHorizontal }
			label={ sprintf(
				/* translators: %s: the item's title. */
				__( 'Actions for %s', 'newspack-intelligence' ),
				item.title || item.item_key
			) }
			toggleProps={ { size: 'small' } }
		>
			{ ( { onClose } ) => {
				const then = ( act ) => () => {
					onClose();
					act();
				};
				return (
					<>
						<MenuGroup
							label={ __(
								'Mark as used in',
								'newspack-intelligence'
							) }
						>
							{ Object.entries( words( 'markWords' ) ).map(
								( [ mark, label ] ) => (
									<MenuItem
										key={ mark }
										onClick={ then( () => onUse( mark ) ) }
									>
										{ label }
									</MenuItem>
								)
							) }
						</MenuGroup>
						{ 0 < others.length && (
							<MenuGroup
								label={ __(
									'Move to',
									'newspack-intelligence'
								) }
							>
								{ others.map( ( folder ) => (
									<MenuItem
										key={ folder.collection_key }
										onClick={ then( () =>
											onMove( folder.collection_key )
										) }
									>
										{ folder.name }
									</MenuItem>
								) ) }
							</MenuGroup>
						) }
						<MenuGroup>
							{ href && (
								<MenuItem
									// MenuItem takes an href its types omit.
									{
										/** @type {Object} */ ...{
											href,
											target: '_blank',
											rel: 'noopener noreferrer',
										}
									}
									onClick={ onClose }
								>
									{ __(
										'Open source',
										'newspack-intelligence'
									) }
								</MenuItem>
							) }
							<MenuItem onClick={ then( onRemove ) }>
								{ __(
									'Remove from folder',
									'newspack-intelligence'
								) }
							</MenuItem>
						</MenuGroup>
					</>
				);
			} }
		</DropdownMenu>
	);
}

/**
 * One saved item on the Folders board: its title citing its URL, the day it
 * was saved, each use mark a badge, else "Not yet used", and the refusal of
 * a write on it. For a curator it drags, and its menu marks it used, moves
 * it or removes it. It takes focus when a move lands it in its column.
 *
 * @param {Object}                              props
 * @param {Object}                              props.item     The saved item, its marks as shown.
 * @param {Object[]}                            props.others   The folders it can move into.
 * @param {boolean}                             props.canTune  Whether it drags and offers its menu.
 * @param {boolean}                             props.focused  Whether a move just landed it here.
 * @param {?string}                             props.refusal  A refused write's words, or null.
 * @param {import('react').DragEventHandler}    props.onPickUp Starts a drag.
 * @param {(into: string) => void}              props.onMove   Moves it.
 * @param {(mark: string, day: string) => void} props.onMark   Marks it used.
 * @param {() => void}                          props.onRemove Removes it from the folder.
 * @return {import('react').ReactElement} The card.
 */
export default function FolderCard( {
	item,
	others,
	canTune,
	focused,
	refusal,
	onPickUp,
	onMove,
	onMark,
	onRemove,
} ) {
	const titleId = useId();
	const ref = useRef( null );
	const [ marking, setMarking ] = useState( null );
	const today = utcDay( Math.floor( Core.now() ) );
	useEffect( () => {
		if ( focused ) {
			ref.current?.focus();
		}
	}, [ focused ] );
	return (
		<Card
			as="article"
			ref={ ref }
			size="small"
			tabIndex={ -1 }
			aria-labelledby={ titleId }
			className="newspack-intelligence__folder-card"
			draggable={ canTune || undefined }
			onDragStart={ canTune ? onPickUp : undefined }
		>
			<CardBody>
				<strong id={ titleId }>
					<Cite url={ item.url } title={ item.title } />
				</strong>
				<p className="newspack-nodes-status is-muted">
					{ sprintf(
						/* translators: %s: the day it was saved, as "5 Nov". */
						__( 'Saved %s', 'newspack-intelligence' ),
						dayLabel( utcDay( item.saved_ts ), today )
					) }
				</p>
				<div className="newspack-intelligence__actions">
					{ 0 === item.marks.length ? (
						<span className="newspack-nodes-status is-muted">
							{ __( 'Not yet used', 'newspack-intelligence' ) }
						</span>
					) : (
						item.marks.map( ( use ) => (
							<span
								key={ use.mark }
								className="newspack-nodes-status-badge is-success is-pill"
							>
								{ markText( use, today ) }
							</span>
						) )
					) }
					{ canTune && (
						<CardMenu
							item={ item }
							others={ others }
							onUse={ setMarking }
							onMove={ onMove }
							onRemove={ onRemove }
						/>
					) }
				</div>
				<Refusal error={ refusal } />
			</CardBody>
			{ marking && (
				<MarkDialog
					mark={ marking }
					onMark={ ( mark, day ) => {
						setMarking( null );
						onMark( mark, day );
					} }
					onClose={ () => setMarking( null ) }
				/>
			) }
		</Card>
	);
}
