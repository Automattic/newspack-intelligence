/**
 * The layout editor of the one context the server localized, mounted on the
 * substrate's `mountComposedEditor`: Intelligence's blocks under the page's
 * own heading, every block reading the page it previews, through the page's
 * shared reads and, on a profile, one `entity` read of the entity
 * previewed, with a picker of the team view or the entity it previews with.
 *
 * The editor half of Intelligence's layouts: only `layout-editor.js`, the
 * bundle `Composition::enqueue_editor()` loads for a `tune` caller, reaches
 * it.
 */

import { Button, Dropdown, DropdownMenu } from '@wordpress/components';
import {
	createContext,
	useContext,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { mountComposedEditor } from '@newspack-nodes/shared/composition/page/mountComposedEditor';
import EntityPicker from '../components/EntityPicker';
import { aboutEntity, EntityRecord } from '../blocks/record';
import { GROUP, useIntelRead } from '../hooks/useIntelCommand';
import { PageReads } from '../hooks/usePageRead';
import { layoutContext, PAGES } from './editMode';

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
 * The entity the blocks preview with, picked by name: a pick previews it at
 * once and closes the picker, and Escape closes it once the list has.
 *
 * @param {Object}                props
 * @param {?string}               props.name     The entity previewed, by its name, null before one is picked.
 * @param {(key: string) => void} props.onChange Previews another.
 * @return {import('react').ReactElement} The picker.
 */
function EntityPreview( { name, onChange } ) {
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
				<div className="newspack-intelligence__preview-pick">
					<EntityPicker
						label={ __( 'Preview with', 'newspack-intelligence' ) }
						value=""
						onEscape={ onClose }
						onChange={ ( key ) => {
							if ( key ) {
								onChange( key );
								onClose();
							}
						} }
					/>
				</div>
			) }
		/>
	);
}

/**
 * The picker the blocks preview with, which the editor's page holds.
 *
 * @type {import('react').Context<?import('react').ReactElement>}
 */
const Previewing = createContext( null );

/** Does nothing, as a restore does in the editor, which offers none. */
const nothing = () => {};

/**
 * The page the editor's blocks stand on: the page edited, previewing the
 * team view or the entity it was opened from until another is picked.
 *
 * @param {Object}                                                                props
 * @param {Object}                                                                props.slot        What the page's edit slot hands the editor.
 * @param {import('@newspack-nodes/shared/composition/page/editMode').EditTarget} props.slot.target The page and the subject it was opened from.
 * @param {?number}                                                               props.slot.since  The reader's last look, which the Briefing's blocks read.
 * @param {import('../hooks/usePageRead').SharedReads}                            props.slot.reads  The page's shared reads, which its blocks read.
 * @param {import('react').ReactNode}                                             props.children    The editor.
 * @return {import('react').ReactElement} The editor, on its page.
 */
function EditorPage( { slot: { target, since, reads }, children } ) {
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
			group: GROUP,
			canTune: false,
			onRestored: nothing,
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
				<ComposedPage.Provider value={ page }>
					<Previewing.Provider value={ preview }>
						{ children }
					</Previewing.Provider>
				</ComposedPage.Provider>
			</EntityRecord.Provider>
		</PageReads.Provider>
	);
}

/**
 * The picker the editor's page holds, where the editor places it.
 *
 * @return {?import('react').ReactElement} The picker, or none on the Briefing.
 */
function PreviewWith() {
	return useContext( Previewing );
}

/**
 * Mount the localized context's editor: its blocks against their views,
 * core's left to core, under the page's heading and its page.
 *
 * @param {import('@newspack-nodes/shared/composition/page/mountComposedEditor').LocalizedComposition}       localized What `Composition::editor()` localized.
 * @param {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>} views     Every block's view.
 * @throws {Error} Naming a localized block with no view.
 */
export function mountLayoutEditor( localized, views ) {
	const page = Object.keys( PAGES ).find(
		( one ) => layoutContext( one ) === localized.context
	);
	mountComposedEditor( localized, views, {
		heading: HEADINGS[ page ](),
		wrap: ( children, slot ) => (
			<EditorPage slot={ slot }>{ children }</EditorPage>
		),
		preview: PreviewWith,
	} );
}
