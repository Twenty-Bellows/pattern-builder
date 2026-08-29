/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import {
	BlockControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import { navigateToPattern } from '../utils/patternNavigation';

/**
 * The "Edit Pattern" toolbar button for a synced theme pattern instance on
 * the canvas (a `core/pattern` block), alongside the runtime's Reset /
 * Detach. It opens the referenced pattern the way the sidebar's Edit does:
 * in-context in the post editor, the Pattern Builder editor elsewhere.
 *
 * The button only renders when the slug resolves to a pb_pattern entity —
 * registry-only patterns (from plugins, or core) have no file to edit.
 * (Synced USER pattern instances are `core/block`, where core provides its
 * own "Edit original" button.)
 *
 * @param {Object} props      Component props.
 * @param {string} props.slug The pattern slug the block references.
 */
function EditPatternControls( { slug } ) {
	const { themePattern, onNavigateToEntityRecord } = useSelect(
		( select ) => ( {
			themePattern: slug
				? select( coreStore ).getEntityRecord(
						'postType',
						'pb_pattern',
						slug
				  )
				: null,
			onNavigateToEntityRecord:
				select( blockEditorStore ).getSettings()
					.onNavigateToEntityRecord,
		} ),
		[ slug ]
	);

	if ( ! themePattern ) {
		return null;
	}

	return (
		<BlockControls group="other">
			<ToolbarGroup>
				<ToolbarButton
					onClick={ () =>
						navigateToPattern(
							{ id: themePattern.id, source: 'theme' },
							onNavigateToEntityRecord
						)
					}
				>
					{ __( 'Edit Pattern', 'pattern-builder' ) }
				</ToolbarButton>
			</ToolbarGroup>
		</BlockControls>
	);
}

const withEditPatternButton = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		if ( props.name !== 'core/pattern' || ! props.isSelected ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				<BlockEdit { ...props } />
				<EditPatternControls slug={ props.attributes?.slug } />
			</>
		);
	},
	'withEditPatternButton'
);

export function registerEditPatternToolbarButton() {
	addFilter(
		'editor.BlockEdit',
		'pattern-builder/edit-pattern-toolbar-button',
		withEditPatternButton
	);
}
