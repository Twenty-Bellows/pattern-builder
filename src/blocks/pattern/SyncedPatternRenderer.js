import { useSelect, useDispatch, useRegistry } from '@wordpress/data';
import { cloneBlock } from '@wordpress/blocks';
import { useState, useEffect } from '@wordpress/element';
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';

/**
 *
 * SyncedPatternFilter
 *
 * This filter checks if the block being edited is a core/pattern block with a slug and content.
 * If so, it renders the SyncedPatternRenderer component instead of the default BlockEdit.
 */
export const SyncedPatternFilter = (BlockEdit) => (props) => {
	const { name, attributes } = props;
	if (name === 'core/pattern' && attributes.slug && attributes.content) {
		const selectedPattern = useSelect(
			(select) =>
				select(blockEditorStore).__experimentalGetParsedPattern(
					attributes.slug
				),
			[props.attributes.slug]
		);
		if (selectedPattern?.blocks?.length === 1 && selectedPattern.blocks[0].name === 'core/block') {
			return <SyncedPatternRenderer {
				...props
			} />;
		}
	}
	return <BlockEdit {...props} />;
};

/**
 * SyncedPatternRenderer component
 *
 * Renders a Theme Synced Pattern block, passing the content on to the referenced Core Pattern block.
 */
export const SyncedPatternRenderer = ( { attributes, clientId } ) =>{

	const registry = useRegistry();

	const selectedPattern = useSelect(
		( select ) =>
			select( blockEditorStore ).__experimentalGetParsedPattern(
				attributes.slug
			),
		[ attributes.slug ]
	);

	const {
		replaceBlocks,
		setBlockEditingMode,
		__unstableMarkNextChangeAsNotPersistent,
	} = useDispatch( blockEditorStore );

	const { getBlockRootClientId, getBlockEditingMode } =
		useSelect( blockEditorStore );

	const [ hasRecursionError, setHasRecursionError ] = useState( false );

	useEffect( () => {
		window.queueMicrotask( () => {
			const rootClientId = getBlockRootClientId( clientId );
			const clonedBlocks = selectedPattern.blocks.map( ( block ) => {
				block.attributes.content = attributes.content;
				return cloneBlock(block);
			});

			const rootEditingMode = getBlockEditingMode( rootClientId );
			registry.batch( () => {
				// Temporarily set the root block to default mode to allow replacing the pattern.
				// This could happen when the page is disabling edits of non-content blocks.
				__unstableMarkNextChangeAsNotPersistent();
				setBlockEditingMode( rootClientId, 'default' );
				__unstableMarkNextChangeAsNotPersistent();
				replaceBlocks( clientId, clonedBlocks );
				// Restore the root block's original mode.
				__unstableMarkNextChangeAsNotPersistent();
				setBlockEditingMode( rootClientId, rootEditingMode );
			} );
		} );
	}, [
		clientId,
		hasRecursionError,
		selectedPattern,
		__unstableMarkNextChangeAsNotPersistent,
		replaceBlocks,
		getBlockEditingMode,
		setBlockEditingMode,
		getBlockRootClientId,
	] );

	const props = useBlockProps();

	return <div { ...props } />;
}
