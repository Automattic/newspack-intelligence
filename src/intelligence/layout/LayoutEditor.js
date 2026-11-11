/**
 * The layout editor of the one context the server localized: its blocks
 * registered against their views, and the substrate's `CompositionEditor`
 * registered for the page's edit slot, under the page's own heading, with
 * every block reading the page it previews, through the page's shared reads
 * and, on a profile, one `entity` read of the entity previewed; the substrate
 * hands each block's View `editing`, which leaves its writes inert.
 *
 * The editor half of Intelligence's layouts: only `layout-editor.js`, the
 * bundle `Composition::editor()` loads for a `tune` caller, reaches it.
 */

import { Button, Dropdown, DropdownMenu } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import CompositionEditor from '@newspack-nodes/shared/composition/CompositionEditor';
import { registerCompositionBlocks } from '@newspack-nodes/shared/composition/registerCompositionBlocks';
import { registerCompositionEditor } from '@newspack-nodes/shared/composition/editorRegistry';
import EntityPicker, { useEntityNames } from '../components/EntityPicker';
import { aboutEntity, EntityRecord } from '../blocks/record';
import { GROUP, useIntelRead } from '../hooks/useIntelCommand';
import { PageReads } from '../hooks/usePageRead';
import { IntelPageContext } from './PageContext';

/** Each composed page => the editor's heading. */
const HEADINGS = {
	briefing: () => __( 'Edit the Briefing layout', 'newspack-intelligence' ),
	lens: () => __( 'Edit the team view layout', 'newspack-intelligence' ),
	entity: () => __( 'Edit the profile layout', 'newspack-intelligence' ),
};

/**
 * The toggle's words for what the blocks preview with.
 *
 * @param {string} name The team view's or the entity's name.
 * @return {string} The words.
 */
const previewWith = ( name ) =>
	/* translators: %s: the team view or the entity the layout previews with. */
	sprintf( __( 'Preview with %s', 'newspack-intelligence' ), name );

/**
 * The team view the blocks preview with, among every saved one.
 *
 * @param {Object}                props
 * @param {Object[]}              props.lenses   Every saved team view.
 * @param {Object}                props.shown    The one previewed.
 * @param {(key: string) => void} props.onChange Previews another.
 * @return {import('react').ReactElement} The picker.
 */
function LensPreview( { lenses, shown, onChange } ) {
	return (
		<DropdownMenu
			icon={ null }
			text={ previewWith( shown.name ) }
			label={ previewWith( shown.name ) }
			toggleProps={ { variant: 'secondary' } }
			controls={ lenses.map( ( lens ) => ( {
				title: lens.name,
				isActive: lens.lens_key === shown.lens_key,
				onClick: () => onChange( lens.lens_key ),
			} ) ) }
		/>
	);
}

/**
 * The entity the blocks preview with, picked by name.
 *
 * @param {Object}                props
 * @param {?string}               props.name     The entity previewed, by its name, null before one is picked.
 * @param {(key: string) => void} props.onChange Previews another.
 * @return {import('react').ReactElement} The picker.
 */
function EntityPreview( { name, onChange } ) {
	const [ text, setText ] = useState( '' );
	const names = useEntityNames( text );
	return (
		<Dropdown
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="secondary"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					{ name
						? previewWith( name )
						: __(
								'Choose an entity to preview with',
								'newspack-intelligence'
						  ) }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<form
					className="newspack-intelligence__preview-pick"
					onSubmit={ ( event ) => {
						event.preventDefault();
						onChange( names.keyOf( text ) );
						onClose();
					} }
				>
					<EntityPicker
						label={ __( 'Preview with', 'newspack-intelligence' ) }
						value={ text }
						onChange={ setText }
						names={ names }
					/>
					<Button variant="primary" type="submit">
						{ __( 'Preview', 'newspack-intelligence' ) }
					</Button>
				</form>
			) }
		/>
	);
}

/**
 * One page's layout editor.
 *
 * @param {Object}                                     props
 * @param {string}                                     props.context The context edited.
 * @param {Object}                                     props.schemas Block name => schema.
 * @param {import('./editMode').EditTarget}            props.target  The page and the subject it was opened from.
 * @param {?number}                                    props.since   The reader's last look, which the Briefing's blocks read.
 * @param {import('../hooks/usePageRead').SharedReads} props.reads   The page's shared reads, which its blocks read.
 * @param {(answer: ?Object, verb: ?string) => void}   props.onDone  Hands the page the layout it renders next.
 * @param {(dirty: boolean) => void}                   props.onDirty Tells the page whether a change is not yet saved.
 * @return {import('react').ReactElement} The editor.
 */
function LayoutEditor( {
	context,
	schemas,
	target,
	since,
	reads,
	onDone,
	onDirty,
} ) {
	const [ lensKey, setLensKey ] = useState( target.lens_key ?? null );
	const [ entityKey, setEntityKey ] = useState( target.entity_key ?? null );
	const lenses =
		'lens' === target.page ? reads.lenses.answer?.lenses : undefined;
	const shown =
		lenses?.find( ( lens ) => lensKey === lens.lens_key ) ??
		lenses?.[ 0 ] ??
		null;
	const record = useIntelRead(
		'layout:entity',
		'entity',
		'entity' === target.page && entityKey ? [ entityKey ] : null
	);
	const previewed = shown?.lens_key ?? lensKey;
	const page = useMemo(
		() => ( {
			page: target.page,
			lens_key: previewed,
			entity_key: entityKey,
			since,
		} ),
		[ target.page, previewed, entityKey, since ]
	);
	let preview = null;
	if ( shown ) {
		preview = (
			<LensPreview
				lenses={ lenses }
				shown={ shown }
				onChange={ setLensKey }
			/>
		);
	} else if ( 'entity' === target.page ) {
		preview = (
			<EntityPreview
				name={ record.answer?.name ?? entityKey }
				onChange={ setEntityKey }
			/>
		);
	}
	return (
		<PageReads.Provider value={ reads }>
			<EntityRecord.Provider value={ aboutEntity( entityKey, record ) }>
				<IntelPageContext.Provider value={ page }>
					<CompositionEditor
						group={ GROUP }
						context={ context }
						heading={ HEADINGS[ target.page ]() }
						schemas={ schemas }
						preview={ preview }
						onDone={ onDone }
						onDirty={ onDirty }
					/>
				</IntelPageContext.Provider>
			</EntityRecord.Provider>
		</PageReads.Provider>
	);
}

/**
 * Register the localized context's blocks against their views, then its
 * editor for the page's edit slot.
 *
 * @param {{context: string, blocks: Object}}                                                                localized What `Composition::editor()` localized.
 * @param {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>} views     Every block's view.
 * @throws {Error} Naming a localized block with no view.
 */
export function mountLayoutEditor( { context, blocks }, views ) {
	registerCompositionBlocks(
		blocks,
		Object.fromEntries(
			Object.entries( views ).filter( ( [ name ] ) =>
				Object.hasOwn( blocks, name )
			)
		)
	);
	registerCompositionEditor( context, ( props ) => (
		<LayoutEditor context={ context } schemas={ blocks } { ...props } />
	) );
}
