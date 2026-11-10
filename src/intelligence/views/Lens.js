import { useCallback, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	DropdownMenu,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { moreHorizontal } from '@wordpress/icons';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import {
	LENS_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { AnswerBody } from '../components/Answer';
import ListField from '../components/ListField';
import ListPicker from '../components/ListPicker';
import { Invitation } from '../components/Section';
import { word } from '../components/words';
import Refusal from '../components/Refusal';
import {
	draftArgs,
	draftLens,
	lensDraft,
	listOf,
	newLens,
} from '../components/lensDraft';
import { editLayoutControl } from '../layout/LayoutMenu';
import PageLayout from '../layout/PageLayout';

/** No view chosen, so the first listed opens. */
export const CLOSED_LENS = { key: null };

/** The look-back spans the form offers, in days. */
const LOOK_BACK_DAYS = [ 7, 14, 30, 60, 90, 180, 365 ];

/**
 * A listed lens's key.
 *
 * @param {Object} lens The lens.
 * @return {string} Its key.
 */
const lensKey = ( lens ) => lens.lens_key;

/**
 * A select's options over a closed list, each by its word, led by the
 * choice setting none.
 *
 * @param {string[]} values The list.
 * @param {string}   map    The vocabulary map wording it.
 * @return {{label: string, value: string}[]} The options.
 */
const choicesOf = ( values, map ) => [
	{ label: __( 'Not set', 'newspack-intelligence' ), value: '' },
	...values.map( ( value ) => ( { label: word( map, value ), value } ) ),
];

/**
 * The look-back options, "%d days" each, the draft's own among them.
 *
 * @param {string} days The draft's days, '' for none.
 * @return {{label: string, value: string}[]} The options.
 */
const lookBackOf = ( days ) => [
	{ label: __( 'Not set', 'newspack-intelligence' ), value: '' },
	...[
		...new Set( [
			...LOOK_BACK_DAYS,
			...( days ? [ Number( days ) ] : [] ),
		] ),
	]
		.sort( ( a, b ) => a - b )
		.map( ( n ) => ( {
			label: sprintf(
				/* translators: %d: how many days a view looks back. */
				_n( '%d day', '%d days', n, 'newspack-intelligence' ),
				n
			),
			value: String( n ),
		} ) ),
];

/**
 * What a view's rail entry says of its week.
 *
 * @param {number} count Items the view admits from the last seven days.
 * @return {string} The count.
 */
const newCount = ( count ) =>
	sprintf(
		/* translators: %d: items the view admits from the last seven days. */
		__( '%d new', 'newspack-intelligence' ),
		count
	);

/**
 * The one dialog a curator saves a view with, a new one or the one open:
 * its name and what it is for, then every filter. A filter left blank is
 * left out, so the view does not set it; the key derives from the name and
 * is never shown.
 *
 * @param {Object}                                  props
 * @param {Object<string, string>}                  props.initial    The draft it opens on.
 * @param {Object}                                  props.vocabulary The ontology's closed lists.
 * @param {?string}                                 props.refusal    Why the last save was refused.
 * @param {(draft: Object<string, string>) => void} props.onSave     Sends the draft.
 * @param {() => void}                              props.onCancel   Closes the dialog unsent.
 * @return {import('react').ReactElement} The dialog.
 */
function LensForm( { initial, vocabulary, refusal, onSave, onCancel } ) {
	const [ draft, setDraft ] = useState( initial );
	const field = ( name ) => ( {
		value: draft[ name ],
		onChange: ( value ) => setDraft( { ...draft, [ name ]: value } ),
		__next40pxDefaultSize: true,
	} );
	const tiers = listOf( draft.tier );
	return (
		<Modal onClose={ onCancel } onSubmit={ () => onSave( draft ) }>
			<ModalHeader
				title={
					initial.key
						? sprintf(
								/* translators: %s: the view's name. */
								__( 'Edit %s', 'newspack-intelligence' ),
								initial.name
						  )
						: __( 'New team view', 'newspack-intelligence' )
				}
				onClose={ onCancel }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<Refusal error={ refusal } />
			<TextControl
				label={ __( 'Name', 'newspack-intelligence' ) }
				{ ...field( 'name' ) }
			/>
			<TextControl
				label={ __( 'What it is for', 'newspack-intelligence' ) }
				{ ...field( 'description' ) }
			/>
			<ListPicker
				label={ __( 'Kinds of news', 'newspack-intelligence' ) }
				map="kindWords"
				values={ vocabulary.itemKinds }
				{ ...field( 'kind' ) }
			/>
			<ListField
				label={ __( 'Topics', 'newspack-intelligence' ) }
				placeholder="topic:local-news"
				{ ...field( 'topic' ) }
			/>
			<ListPicker
				label={ __( 'About', 'newspack-intelligence' ) }
				map="roleWords"
				values={ vocabulary.roles }
				{ ...field( 'role' ) }
			/>
			<ListPicker
				label={ __( 'Sources', 'newspack-intelligence' ) }
				map="sourceWords"
				values={ null }
				{ ...field( 'source' ) }
			/>
			<TextControl
				label={ __( 'Client or company', 'newspack-intelligence' ) }
				{ ...field( 'entity' ) }
			/>
			<SelectControl
				label={ __( 'Look back', 'newspack-intelligence' ) }
				options={ lookBackOf( draft.days ) }
				{ ...field( 'days' ) }
			/>
			<SelectControl
				label={ __( 'Chart by', 'newspack-intelligence' ) }
				options={ choicesOf( vocabulary.trendAxes, 'axisWords' ) }
				{ ...field( 'by' ) }
			/>
			<SelectControl
				label={ __( 'Group by', 'newspack-intelligence' ) }
				options={ choicesOf( vocabulary.buckets, 'spanWords' ) }
				{ ...field( 'bucket' ) }
			/>
			<fieldset className="newspack-intelligence__choices">
				<legend>
					{ __( 'Include items that are', 'newspack-intelligence' ) }
				</legend>
				{ vocabulary.tiers.map( ( tier ) => (
					<CheckboxControl
						key={ tier }
						label={ word( 'tierWords', tier ) }
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
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button type="submit" className={ primaryButtonClass() }>
					{ __( 'Save view', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

/**
 * The dialog asking before a view is removed.
 *
 * @param {Object}     props
 * @param {string}     props.name     The view's name.
 * @param {() => void} props.onRemove Removes it.
 * @param {() => void} props.onCancel Keeps it.
 * @return {import('react').ReactElement} The dialog.
 */
function RemoveDialog( { name, onRemove, onCancel } ) {
	const asked = sprintf(
		/* translators: %s: the view's name. */
		__(
			'Remove the %s view? Watches that follow it will stop.',
			'newspack-intelligence'
		),
		name
	);
	return (
		<Modal ariaLabel={ asked } onClose={ onCancel }>
			<p>{ asked }</p>
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className={ primaryButtonClass( false, 'is-danger' ) }
					onClick={ onRemove }
				>
					{ __( 'Remove view', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

/**
 * The views to pick from: a rail of each with what is new in it this week,
 * or below 600 px a select.
 *
 * @param {Object}                props
 * @param {Object[]}              props.lenses The views listed.
 * @param {string}                props.open   The key of the view open.
 * @param {(key: string) => void} props.onPick Opens a view.
 * @return {import('react').ReactElement} The rail.
 */
function Rail( { lenses, open, onPick } ) {
	const narrow = useNarrow();
	if ( narrow ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Team view', 'newspack-intelligence' ) }
				value={ open }
				options={ lenses.map( ( lens ) => ( {
					label: sprintf(
						/* translators: 1: the view's name, 2: what is new in it this week. */
						__( '%1$s, %2$s', 'newspack-intelligence' ),
						lens.name,
						newCount( lens.week_items )
					),
					value: lens.lens_key,
				} ) ) }
				onChange={ onPick }
			/>
		);
	}
	return (
		<nav
			className="newspack-intelligence__rail"
			aria-label={ __( 'Team views', 'newspack-intelligence' ) }
		>
			<ul>
				{ lenses.map( ( lens ) => (
					<li key={ lens.lens_key }>
						<button
							type="button"
							className="newspack-intelligence__rail-item"
							aria-current={ open === lens.lens_key || undefined }
							onClick={ () => onPick( lens.lens_key ) }
						>
							<span>{ lens.name }</span>{ ' ' }
							<span className="newspack-nodes-status is-muted">
								{ newCount( lens.week_items ) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</nav>
	);
}

/**
 * Team views: every view in a rail, the one open under its fixed header,
 * which offers every reader **Watch**, and a curator **Edit** and a menu
 * holding **Remove view…** and **Edit layout**; below it the team view
 * layout, its blocks reading the view open. One dialog saves a new view or
 * the one open, and removing one asks first. A save shows its view and a
 * removal takes its view out from the moment each is sent, until a read of
 * `lenses` reflects it, since the projector records it seconds after it
 * lands; a refusal puts the view back. With none chosen, the first opens.
 *
 * @param {Object}                                                props
 * @param {boolean}                                               props.canTune    Whether to offer curation.
 * @param {Object}                                                props.vocabulary The ontology's closed lists.
 * @param {{key: ?string}}                                        props.opened     The view chosen.
 * @param {(opened: {key: ?string}) => void}                      props.onOpen     Chooses a view.
 * @param {(target: Object, name: string) => void}                props.onWatch    Opens the watch form on a target.
 * @param {Object}                                                props.layout     What the page data answered for the team view's context.
 * @param {Partial<import('../layout/PageContext').IntelActions>} props.actions    What the blocks' rows open.
 * @param {(layout: Object) => void}                              props.onRestored Takes the default a curator restored.
 * @return {import('react').ReactElement} The view.
 */
export default function Lens( {
	canTune,
	vocabulary,
	opened,
	onOpen,
	onWatch,
	layout,
	actions,
	onRestored,
} ) {
	const lists = vocabulary.lensLists;
	const [ editing, setEditing ] = useState( null );
	const [ removing, setRemoving ] = useState( null );
	const list = useIntelRead( 'lens:list', 'lenses', [] );
	/**
	 * A lens as its form's text, its key aside, since the server slugs a new one.
	 *
	 * @param {Object} one A lens.
	 * @return {string} The text.
	 */
	const textOf = useCallback(
		( one ) => {
			const { key, ...draft } = lensDraft( one, lists );
			return JSON.stringify( draft );
		},
		[ lists ]
	);
	// Reflected once listed as sent, or listed changed since the send.
	const matches = useCallback(
		( listed, held ) => {
			const shown = textOf( listed );
			if ( shown === textOf( held ) ) {
				return true;
			}
			return null === held.before
				? listed.name === held.name
				: listed.lens_key === held.lens_key && shown !== held.before;
		},
		[ textOf ]
	);
	const lenses = useHeld( list, 'lenses', lensKey, matches );
	const save = useIntelWrite( 'save_lens', {
		dirties: LENS_READS,
		refresh: () => setEditing( null ),
		onRefused: lenses.refused,
	} );
	const remove = useIntelWrite( 'remove_lens', {
		dirties: LENS_READS,
		refresh: () => onOpen( CLOSED_LENS ),
		onRefused: lenses.refused,
	} );
	const newView = () => setEditing( newLens( lists ) );
	const listed = lenses.read.answer?.lenses ?? [];
	const lens =
		listed.find( ( one ) => one.lens_key === opened.key ) ?? listed[ 0 ];

	return (
		<div className="newspack-intelligence__view">
			<div className="newspack-intelligence__section-head">
				<h2>{ __( 'Team views', 'newspack-intelligence' ) }</h2>
				{ canTune && (
					<button
						type="button"
						className="button"
						onClick={ newView }
					>
						{ __( 'New view', 'newspack-intelligence' ) }
					</button>
				) }
			</div>
			<p className="newspack-nodes-status is-muted">
				{ __(
					'Saved views for each team, with what moved this week.',
					'newspack-intelligence'
				) }
			</p>
			<Refusal error={ remove.refusal } />
			<AnswerBody
				read={ lenses.read }
				rows="lenses"
				empty={
					canTune ? (
						<Invitation
							text={ __(
								'No team views yet. A view saves a set of filters and charts what moves inside it.',
								'newspack-intelligence'
							) }
							label={ __( 'New view', 'newspack-intelligence' ) }
							onClick={ newView }
						/>
					) : (
						__(
							'No team views yet. A view saves a set of filters and charts what moves inside it.',
							'newspack-intelligence'
						)
					)
				}
			>
				{ ( answer ) => (
					<div className="newspack-intelligence__lens-page">
						<Rail
							lenses={ answer.lenses }
							open={ lens.lens_key }
							onPick={ ( key ) => onOpen( { key } ) }
						/>
						<div className="newspack-intelligence__lens-main">
							<div className="newspack-intelligence__section-head">
								<h2>{ lens.name }</h2>
								<div className="newspack-intelligence__actions">
									<button
										type="button"
										className="button"
										onClick={ () =>
											onWatch(
												{
													kind: 'lens',
													key: lens.lens_key,
												},
												lens.name
											)
										}
									>
										{ __(
											'Watch',
											'newspack-intelligence'
										) }
									</button>
									{ canTune && (
										<>
											<button
												type="button"
												className="button"
												onClick={ () =>
													setEditing(
														lensDraft( lens, lists )
													)
												}
											>
												{ __(
													'Edit',
													'newspack-intelligence'
												) }
											</button>
											<DropdownMenu
												icon={ moreHorizontal }
												label={ __(
													'Team view actions',
													'newspack-intelligence'
												) }
												toggleProps={ {
													size: 'small',
												} }
												controls={ [
													{
														title: __(
															'Remove view…',
															'newspack-intelligence'
														),
														onClick: () =>
															setRemoving( lens ),
													},
													editLayoutControl( {
														page: 'lens',
														lens_key: lens.lens_key,
													} ),
												] }
											/>
										</>
									) }
								</div>
							</div>
							{ lens.description && (
								<p className="newspack-nodes-status is-muted">
									{ lens.description }
								</p>
							) }
							<PageLayout
								key={ JSON.stringify( lens.filters ) }
								layout={ layout }
								onRestored={ onRestored }
								page={ {
									page: 'lens',
									lens_key: lens.lens_key,
									entity_key: null,
									since: null,
									actions,
								} }
							/>
						</div>
					</div>
				) }
			</AnswerBody>
			{ editing && (
				<LensForm
					initial={ editing }
					vocabulary={ vocabulary }
					refusal={ save.refusal }
					onSave={ ( draft ) => {
						const was = listed.find(
							( one ) => draft.key && draft.key === one.lens_key
						);
						lenses.hold(
							draft.key || draft.name,
							{
								...draftLens(
									{ ...draft, key: draft.key || draft.name },
									lists
								),
								before: was ? textOf( was ) : null,
							},
							() => save.run( draftArgs( draft, lists ) )
						);
					} }
					onCancel={ () => setEditing( null ) }
				/>
			) }
			{ removing && (
				<RemoveDialog
					name={ removing.name }
					onRemove={ () => {
						setRemoving( null );
						lenses.hold( removing.lens_key, null, () =>
							remove.run( { key: removing.lens_key } )
						);
					} }
					onCancel={ () => setRemoving( null ) }
				/>
			) }
		</div>
	);
}
