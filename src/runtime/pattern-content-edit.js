/**
 * Expands a pattern block that carries content, in the editor canvas.
 */

import { cloneBlock } from '@wordpress/blocks';
import {
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

import { applyContent } from './apply-content';
import { isSyncedPattern } from './synced-patterns';
import { SyncedPatternEditWithRecursionCheck } from './synced-pattern-edit';

/**
 * Replaces a pattern block with the pattern's blocks, content written in.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @param {string} props.clientId   Block client ID.
 * @return {JSX.Element} A placeholder, until the replacement lands.
 */
export function PatternContentEdit( { attributes, clientId } ) {
	const registry = useRegistry();
	const blockProps = useBlockProps();

	const pattern = useSelect(
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

	const { content } = attributes;

	useEffect( () => {
		if ( ! pattern?.blocks?.length ) {
			return;
		}

		window.queueMicrotask( () => {
			const blocks = applyContent( pattern.blocks, content ).map(
				( block ) => cloneBlock( block )
			);

			const rootClientId = getBlockRootClientId( clientId );
			const rootEditingMode = getBlockEditingMode( rootClientId );

			registry.batch( () => {
				__unstableMarkNextChangeAsNotPersistent();
				setBlockEditingMode( rootClientId, 'default' );
				__unstableMarkNextChangeAsNotPersistent();
				replaceBlocks( clientId, blocks );
				__unstableMarkNextChangeAsNotPersistent();
				setBlockEditingMode( rootClientId, rootEditingMode );
			} );
		} );
	}, [
		clientId,
		content,
		pattern,
		registry,
		getBlockEditingMode,
		getBlockRootClientId,
		replaceBlocks,
		setBlockEditingMode,
		__unstableMarkNextChangeAsNotPersistent,
	] );

	return <div { ...blockProps } />;
}

/**
 * Uses the component above for pattern blocks that carry content.
 *
 * @param {Function} BlockEdit The block's edit component.
 * @return {Function} The filtered edit component.
 */
export const withPatternContent = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const { name, attributes } = props;

		if ( name === 'core/pattern' && attributes?.slug ) {
			if ( isSyncedPattern( attributes.slug ) ) {
				return <SyncedPatternEditWithRecursionCheck { ...props } />;
			}
			if ( attributes.content ) {
				return <PatternContentEdit { ...props } />;
			}
		}

		return <BlockEdit { ...props } />;
	},
	'withPatternContent'
);

addFilter(
	'editor.BlockEdit',
	'pattern-builder/pattern-content-edit',
	withPatternContent
);
