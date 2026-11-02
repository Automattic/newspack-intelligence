import { __, sprintf } from '@wordpress/i18n';
import { useIntelRead } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import ItemRow from '../components/ItemRow';
import { EntityChip } from '../components/Cite';
import TrendTable, {
	againstBaseline,
	signalValue,
} from '../components/TrendTable';
import { WatchRow } from './Watches';

/**
 * An empty slice's words and the one control that acts on them.
 *
 * @param {Object}     props
 * @param {string}     props.text    What the slice found, and what to do.
 * @param {string}     props.label   The control's label.
 * @param {() => void} props.onClick What the control does.
 * @return {import('react').ReactElement} The invitation.
 */
function Invitation( { text, label, onClick } ) {
	return (
		<>
			{ text }{ ' ' }
			<button type="button" className="button" onClick={ onClick }>
				{ label }
			</button>
		</>
	);
}

/**
 * A run of the briefing's rows under its heading: a client, a lens or
 * Industry.
 *
 * @param {Object}                    props
 * @param {import('react').ReactNode} props.title    The group's heading.
 * @param {import('react').ReactNode} props.children Its rows.
 * @return {import('react').ReactElement} The group.
 */
function Group( { title, children } ) {
	return (
		<section>
			<h3>{ title }</h3>
			{ children }
		</section>
	);
}

/**
 * The week's changes: each client's items under its chip, then the rest
 * under Industry, and how many the week held when the briefing shows fewer.
 *
 * @param {Object}                 props
 * @param {Object}                 props.answer       The `briefing changes` answer.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The groups.
 */
function Changes( { answer, onOpenEntity, onSave } ) {
	const shown = new Set(
		answer.groups.flatMap( ( group ) =>
			group.items.map( ( item ) => item.item_key )
		)
	).size;
	return (
		<>
			{ shown < answer.total && (
				<p className="newspack-nodes-status is-muted">
					{ sprintf(
						/* translators: 1: items shown, 2: items about any entity in the window. */
						__(
							'The %1$d most important of %2$d items about clients and the industry in the last seven days.',
							'newspack-intelligence'
						),
						shown,
						answer.total
					) }
				</p>
			) }
			{ answer.groups.map( ( group ) => (
				<Group
					key={ group.entity_key ?? '' }
					title={
						group.entity_key ? (
							<EntityChip
								entityKey={ group.entity_key }
								name={ group.name }
								onOpen={ onOpenEntity }
							/>
						) : (
							__( 'Industry', 'newspack-intelligence' )
						)
					}
				>
					<ul className="newspack-intelligence__items">
						{ group.items.map( ( item ) => (
							<ItemRow
								key={ item.item_key }
								item={ item }
								onOpenEntity={ onOpenEntity }
								onSave={ onSave }
							/>
						) ) }
					</ul>
				</Group>
			) ) }
		</>
	);
}

/**
 * Briefing: what changed in the last seven days, each its own read — the
 * two `briefing` slices, the week's items about clients and the industry,
 * ranked by importance and grouped by client, and every client's and lens's
 * signals; and `watches`, the reader's own, each as the Watches view lists
 * it. Each row opens
 * the item, its client, its lens or its watch; each empty slice invites the
 * one thing the reader can do about it. Nothing polls.
 *
 * @param {Object}                 props
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(key: ?string) => void} props.onOpenLens   Opens a lens, or the list for null.
 * @param {(key: string) => void}  props.onOpenWatch  Opens a watch.
 * @param {() => void}             props.onNewWatch   Opens the form for a new watch.
 * @param {() => void}             props.onSearch     Opens Search.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Briefing( {
	onOpenEntity,
	onOpenLens,
	onOpenWatch,
	onNewWatch,
	onSearch,
	onSave,
} ) {
	const changes = useIntelRead( 'briefing:changes', 'briefing', [
		'changes',
	] );
	const signals = useIntelRead( 'briefing:signals', 'briefing', [
		'signals',
	] );
	const watches = useIntelRead( 'briefing:watches', 'watches', [] );
	const table = ( rows ) => (
		<TrendTable
			rows={ rows }
			value={ signalValue }
			count={ againstBaseline }
		/>
	);

	return (
		<div className="newspack-intelligence__view">
			<Answer
				title={ __( 'Last seven days', 'newspack-intelligence' ) }
				read={ changes }
				rows="groups"
				empty={
					<Invitation
						text={ __(
							'Nothing about a client or the industry arrived in the last seven days.',
							'newspack-intelligence'
						) }
						label={ __(
							'Search everything',
							'newspack-intelligence'
						) }
						onClick={ onSearch }
					/>
				}
			>
				{ ( answer ) => (
					<Changes
						answer={ answer }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
					/>
				) }
			</Answer>

			<Answer
				title={ __( 'Signals', 'newspack-intelligence' ) }
				read={ signals }
				rows={ [ 'clients', 'lenses' ] }
				empty={
					<Invitation
						text={ __(
							'No client or lens moved off its baseline in the last seven days.',
							'newspack-intelligence'
						) }
						label={ __( 'Open lenses', 'newspack-intelligence' ) }
						onClick={ () => onOpenLens( null ) }
					/>
				}
			>
				{ ( answer ) => (
					<>
						{ answer.clients.map( ( client ) => (
							<Group
								key={ client.entity_key }
								title={
									<EntityChip
										entityKey={ client.entity_key }
										name={ client.name }
										onOpen={ onOpenEntity }
									/>
								}
							>
								{ table( client.signals ) }
							</Group>
						) ) }
						{ answer.lenses.map( ( lens ) => (
							<Group
								key={ lens.lens_key }
								title={
									<button
										type="button"
										className="button button-small"
										onClick={ () =>
											onOpenLens( lens.lens_key )
										}
									>
										{ lens.name }
									</button>
								}
							>
								{ table( lens.signals ) }
							</Group>
						) ) }
					</>
				) }
			</Answer>

			<Answer
				title={ __( 'Your watches', 'newspack-intelligence' ) }
				read={ watches }
				rows="watches"
				empty={
					<Invitation
						text={ __(
							'Watch a client or a lens to see what is new for you here.',
							'newspack-intelligence'
						) }
						label={ __( 'New watch', 'newspack-intelligence' ) }
						onClick={ onNewWatch }
					/>
				}
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.watches.map( ( watch ) => (
							<WatchRow
								key={ watch.watch_key }
								watch={ watch }
								onOpen={ onOpenWatch }
							/>
						) ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}
