import { useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useIntelRead } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import Skeleton from '../components/Skeleton';
import AskBox from '../components/AskBox';
import ItemRow from '../components/ItemRow';
import { EntityChip } from '../components/Cite';
import { sinceLabel, utcDay } from '../components/format';
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

/** The most lines the summary shows: the whole, then the kinds. */
const SUMMARY_LINES = 5;

/** The kinds whose line counts the clients their items are about. */
const CLIENT_KINDS = [ 'churned', 'churn_signal' ];

/**
 * Each kind's words for its count, by kind.
 *
 * @return {Object<string, (n: number) => string>} The words.
 */
const kindLines = () => ( {
	churned: ( n ) =>
		/* translators: %d: clients. */
		_n(
			'%d client started offboarding',
			'%d clients started offboarding',
			n,
			'newspack-intelligence'
		),
	churn_signal: ( n ) =>
		/* translators: %d: clients. */
		_n(
			'%d client showed a churn signal',
			'%d clients showed churn signals',
			n,
			'newspack-intelligence'
		),
	incident: ( n ) =>
		/* translators: %d: incidents. */
		_n(
			'%d incident reported',
			'%d incidents reported',
			n,
			'newspack-intelligence'
		),
	bug: ( n ) =>
		/* translators: %d: bugs. */
		_n( '%d bug filed', '%d bugs filed', n, 'newspack-intelligence' ),
	launch: ( n ) =>
		/* translators: %d: launches. */
		_n( '%d launch', '%d launches', n, 'newspack-intelligence' ),
	funding: ( n ) =>
		/* translators: %d: funding announcements. */
		_n(
			'%d funding announcement',
			'%d funding announcements',
			n,
			'newspack-intelligence'
		),
	award: ( n ) =>
		/* translators: %d: awards. */
		_n( '%d award', '%d awards', n, 'newspack-intelligence' ),
	feature_request: ( n ) =>
		/* translators: %d: feature requests. */
		_n(
			'%d feature request',
			'%d feature requests',
			n,
			'newspack-intelligence'
		),
	complaint: ( n ) =>
		/* translators: %d: complaints. */
		_n( '%d complaint', '%d complaints', n, 'newspack-intelligence' ),
} );

/**
 * What arrived since the reader last looked: how many items and the clients
 * they are about, then each kind the answer counts, in its order, each a
 * line opening the search for it, at most SUMMARY_LINES in all; when
 * nothing arrived since, it says so, naming the instant as the heading does.
 *
 * @param {Object}                    props
 * @param {Object}                    props.summary     The `changes` answer's summary.
 * @param {string}                    props.label       Its instant, as `sinceLabel()` names it.
 * @param {(filters: Object) => void} props.onSearchFor Opens Search on filters.
 * @return {import('react').ReactElement} The summary.
 */
function Summary( { summary, label, onSearchFor } ) {
	const since = utcDay( summary.since );
	const words = kindLines();
	const kinds = summary.kinds
		.map( ( { kind, items, clients } ) => ( {
			kind,
			n: CLIENT_KINDS.includes( kind ) ? clients : items,
		} ) )
		.filter( ( { n } ) => 0 < n )
		.slice( 0, SUMMARY_LINES - 1 );
	const line = ( text, filters ) => (
		<li key={ filters.kind ?? '' }>
			<button
				type="button"
				className="button-link"
				onClick={ () => onSearchFor( { ...filters, since } ) }
			>
				{ text }
			</button>
		</li>
	);
	return 0 === summary.items ? (
		<p className="newspack-nodes-status is-muted">
			{ sprintf(
				/* translators: %s: when the reader last looked, e.g. "Tuesday at 14:31". */
				__( 'Nothing new since %s.', 'newspack-intelligence' ),
				label
			) }
		</p>
	) : (
		<ul className="newspack-intelligence__facts">
			{ line(
				sprintf(
					/* translators: 1: new items, 2: clients they are about. */
					__( '%1$s about %2$s', 'newspack-intelligence' ),
					sprintf(
						/* translators: %d: new items. */
						_n(
							'%d new item',
							'%d new items',
							summary.items,
							'newspack-intelligence'
						),
						summary.items
					),
					sprintf(
						/* translators: %d: clients. */
						_n(
							'%d client',
							'%d clients',
							summary.clients,
							'newspack-intelligence'
						),
						summary.clients
					)
				),
				{}
			) }
			{ kinds.map( ( { kind, n } ) =>
				line( sprintf( words[ kind ]( n ), n ), { kind } )
			) }
		</ul>
	);
}

/**
 * A run of the briefing's rows under its heading: a client, a lens, the
 * operator or Industry, collapsed to the heading and its count until the
 * count is clicked; the toggle is named by both, and controls the rows.
 *
 * @param {Object}                    props
 * @param {import('react').ReactNode} props.title    The group's heading.
 * @param {string}                    props.count    How many rows it holds, in words.
 * @param {import('react').ReactNode} props.children Its rows.
 * @return {import('react').ReactElement} The group.
 */
function Group( { title, count, children } ) {
	const [ open, setOpen ] = useState( false );
	const id = useId();
	return (
		<section>
			<div className="newspack-intelligence__item-head">
				<h3 id={ `${ id }-title` }>{ title }</h3>
				<button
					type="button"
					id={ `${ id }-count` }
					className="button-link"
					aria-expanded={ open }
					aria-controls={ `${ id }-rows` }
					aria-labelledby={ `${ id }-title ${ id }-count` }
					onClick={ () => setOpen( ! open ) }
				>
					{ count }
				</button>
			</div>
			<div id={ `${ id }-rows` } hidden={ ! open }>
				{ open && children }
			</div>
		</section>
	);
}

/**
 * How many items a group holds, in words.
 *
 * @param {number} n The items.
 * @return {string} The words.
 */
const itemCount = ( n ) =>
	/* translators: %d: items. */
	sprintf( _n( '%d item', '%d items', n, 'newspack-intelligence' ), n );

/**
 * How many signals a group holds, in words.
 *
 * @param {number} n The signals.
 * @return {string} The words.
 */
const signalCount = ( n ) =>
	/* translators: %d: signals. */
	sprintf( _n( '%d signal', '%d signals', n, 'newspack-intelligence' ), n );

/**
 * The week's changes, each group in the answer's order, by its most
 * important item: a client's items under its chip, the operator's own work
 * under the operator's heading and the rest under Industry, and how many
 * the week held when the briefing shows fewer.
 *
 * @param {Object}                          props
 * @param {{name: string, heading: string}} props.operator     Who runs the deployment.
 * @param {Object}                          props.answer       The `briefing changes` answer.
 * @param {(key: string) => void}           props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}          [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                         [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The groups.
 */
function Changes( { operator, answer, onOpenEntity, onSave, canTune } ) {
	const heading = ( group ) => {
		switch ( group.role ) {
			case 'client':
				return (
					<EntityChip
						entityKey={ group.entity_key }
						name={ group.name }
						onOpen={ onOpenEntity }
					/>
				);
			case 'operator_product':
				return operator.heading;
			default:
				return __( 'Industry', 'newspack-intelligence' );
		}
	};
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
						/* translators: 1: items shown, 2: items naming any entity in the window, 3: who runs the deployment. */
						__(
							'The %1$d most important of %2$d items about clients, %3$s and the industry in the last seven days.',
							'newspack-intelligence'
						),
						shown,
						answer.total,
						operator.name
					) }
				</p>
			) }
			{ answer.groups.map( ( group ) => (
				<Group
					key={ group.entity_key ?? group.role ?? '' }
					title={ heading( group ) }
					count={ itemCount( group.items.length ) }
				>
					<ul className="newspack-intelligence__items">
						{ group.items.map( ( item ) => (
							<ItemRow
								key={ item.item_key }
								item={ item }
								onOpenEntity={ onOpenEntity }
								onSave={ onSave }
								canTune={ canTune }
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
 * two `briefing` slices, the week's items about clients, the operator and
 * the industry, ranked by importance and grouped by client, the operator or
 * Industry, and every client's and lens's signals; and `watches`, the
 * reader's own, each as the Watches view lists it. Each group shows its
 * heading and count until it is opened. Each row opens the item, its
 * client, its lens or its watch; each empty slice invites the one thing
 * the reader can do about it. Nothing polls.
 *
 * It opens with what arrived since the reader last looked, the session's
 * baseline the page holds, headed "Since" the instant the answer counts
 * from; a reader who never looked sees the week's arrivals headed "This
 * week", and a reader whose storage refuses sees the Briefing without
 * them. It tells the page it is seen while mounted, so the page knows when
 * a leave marks a new look.
 *
 * @param {Object}                          props
 * @param {?number}                         props.since        The last look, UTC seconds, or null for none.
 * @param {boolean}                         props.kept         Whether the browser keeps the last look.
 * @param {(visible: boolean) => void}      props.noteVisible  Tells the page whether it is seen.
 * @param {{name: string, heading: string}} props.operator     Who runs the deployment, by name and as a heading.
 * @param {(filters: Object) => void}       props.onSearchFor  Opens Search on filters.
 * @param {(key: string) => void}           props.onOpenEntity Opens an entity.
 * @param {(key: ?string) => void}          props.onOpenLens   Opens a lens, or the list for null.
 * @param {(key: string) => void}           props.onOpenWatch  Opens a watch.
 * @param {() => void}                      props.onNewWatch   Opens the form for a new watch.
 * @param {() => void}                      props.onSearch     Opens Search.
 * @param {(item: Object) => void}          [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                         [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @param {?Object}                         props.asked        The ask the page holds, which its AskBox shows.
 * @param {(asked: ?Object) => void}        props.onAsked      Holds a new one.
 * @return {import('react').ReactElement} The view.
 */
export default function Briefing( {
	since,
	kept,
	noteVisible,
	operator,
	onSearchFor,
	onOpenEntity,
	onOpenLens,
	onOpenWatch,
	onNewWatch,
	onSearch,
	onSave,
	canTune,
	asked,
	onAsked,
} ) {
	useEffect( () => {
		noteVisible( true );
		return () => noteVisible( false );
	}, [ noteVisible ] );
	const changes = useIntelRead( 'briefing:changes', 'briefing', [
		'changes',
		...( null === since ? [] : [ `--since=${ since }` ] ),
	] );
	const signals = useIntelRead( 'briefing:signals', 'briefing', [
		'signals',
	] );
	const watches = useIntelRead( 'briefing:watches', 'watches', [] );
	const read = true === changes.answer?.available;
	const label = read
		? sinceLabel(
				changes.answer.summary.since,
				Math.floor( Date.now() / 1000 )
		  )
		: '';
	const table = ( rows ) => (
		<TrendTable
			rows={ rows }
			value={ signalValue }
			count={ againstBaseline }
			onSave={ onSave }
		/>
	);

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
			{ kept && read && (
				<Answer
					title={
						null === since
							? __( 'This week', 'newspack-intelligence' )
							: sprintf(
									/* translators: %s: when the reader last looked, e.g. "Tuesday at 14:31". */
									__( 'Since %s', 'newspack-intelligence' ),
									label
							  )
					}
					read={ changes }
				>
					{ ( answer ) => (
						<Summary
							summary={ answer.summary }
							label={ label }
							onSearchFor={ onSearchFor }
						/>
					) }
				</Answer>
			) }
			<Answer
				title={ __( 'Last seven days', 'newspack-intelligence' ) }
				read={ changes }
				rows="groups"
				empty={
					<Invitation
						text={ sprintf(
							/* translators: %s: who runs the deployment. */
							__(
								'Nothing about a client, %s or the industry arrived in the last seven days.',
								'newspack-intelligence'
							),
							operator.name
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
						operator={ operator }
						answer={ answer }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
				) }
			</Answer>

			<Answer
				title={ __( 'Signals', 'newspack-intelligence' ) }
				read={ signals }
				skeleton={ <Skeleton shape="line" count={ 3 } /> }
				rows={ [ 'clients', 'lenses' ] }
				empty={
					<Invitation
						text={ __(
							'No client or team view moved off its baseline in the last seven days.',
							'newspack-intelligence'
						) }
						label={ __(
							'Open team views',
							'newspack-intelligence'
						) }
						onClick={ () => onOpenLens( null ) }
					/>
				}
			>
				{ ( answer ) => (
					<>
						{ answer.clients.map( ( client ) => (
							<Group
								key={ client.entity_key }
								count={ signalCount( client.signals.length ) }
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
								count={ signalCount( lens.signals.length ) }
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
							'Watch a client or a team view to see what is new for you here.',
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
