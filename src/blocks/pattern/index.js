import { addFilter } from '@wordpress/hooks';
import { useSelect, useDispatch, useRegistry } from '@wordpress/data';
import { cloneBlock } from '@wordpress/blocks';
import { useState, useEffect } from '@wordpress/element';
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';

const SyncedPattern = ( { attributes, clientId } ) =>{

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


addFilter(
	'editor.BlockEdit',
	'pattern-builder/pattern-edit',
	( BlockEdit ) => ( props ) => {
		const { name, attributes } = props;
		if ( name === 'core/pattern' && attributes.slug && attributes.content ) {
			const selectedPattern = useSelect(
				( select ) =>
					select( blockEditorStore ).__experimentalGetParsedPattern(
						attributes.slug
					),
				[ props.attributes.slug ]
			);
			if(selectedPattern?.blocks?.length === 1 && selectedPattern.blocks[0].name === 'core/block' ) {
				return <SyncedPattern {
					...props
				} />;
			}
		}
		return <BlockEdit { ...props } />;
	}
);
