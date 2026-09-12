/**
 * Renders a synced pattern as a live instance in the editor.
 */

import { cloneBlock } from '@wordpress/blocks';
import {
	BlockControls,
	RecursionProvider,
	useBlockProps,
	useHasRecursion,
	useInnerBlocksProps,
	Warning,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { applyContent } from './apply-content';

const NOOP = () => {};
const EMPTY_ARRAY = [];
const OVERRIDES_SOURCE = 'core/pattern-overrides';

/**
 * Determines whether a block has a slot the instance can fill.
 *
 * @param {Object} block A block.
 * @return {boolean} Whether any attribute is bound to pattern overrides.
 */
function hasContentSlot( block ) {
	const bindings = block.attributes?.metadata?.bindings ?? {};

	return Object.values( bindings ).some(
		( binding ) => binding?.source === OVERRIDES_SOURCE
	);
}

/**
 * Locks the design of an instance, leaving its content slots editable.
 *
 * @param {string} clientId The instance's client ID.
 * @return {void}
 */
function useLockedDesign( clientId ) {
	const blocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks( clientId ),
		[ clientId ]
	);

	const { setBlockEditingMode, unsetBlockEditingMode } =
		useDispatch( blockEditorStore );

	useEffect( () => {
		const seen = [];

		const walk = ( list ) => {
			list.forEach( ( block ) => {
				seen.push( block.clientId );
				setBlockEditingMode(
					block.clientId,
					hasContentSlot( block ) ? 'contentOnly' : 'disabled'
				);
				walk( block.innerBlocks ?? [] );
			} );
		};

		walk( blocks ?? [] );

		return () => {
			seen.forEach( ( id ) => unsetBlockEditingMode( id ) );
		};
	}, [ blocks, setBlockEditingMode, unsetBlockEditingMode ] );
}

/**
 * Removes the pattern metadata the inserter stamps on an instance.
 *
 * @param {Object}   options               Options.
 * @param {Object}   options.metadata      The block's metadata attribute.
 * @param {Function} options.setAttributes Attribute setter.
 * @param {Function} options.markQuiet     Marks the change as not persistent.
 * @return {void}
 */
function useCleanPatternMetadata( { metadata, setAttributes, markQuiet } ) {
	useEffect( () => {
		if ( ! metadata?.patternName ) {
			return;
		}

		const { patternName, ...rest } = metadata;

		markQuiet();
		setAttributes( {
			metadata: Object.keys( rest ).length ? rest : undefined,
		} );
	}, [ metadata, setAttributes, markQuiet ] );
}

/**
 * Renders one instance of a synced pattern.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {string}   props.clientId      Block client ID.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {JSX.Element} The instance.
 */
export function SyncedPatternEdit( { attributes, clientId, setAttributes } ) {
	const { slug, content, metadata } = attributes;

	const pattern = useSelect(
		( select ) =>
			select( blockEditorStore ).__experimentalGetParsedPattern( slug ),
		[ slug ]
	);

	const {
		replaceBlocks,
		__unstableMarkNextChangeAsNotPersistent: markQuiet,
		__unstableMarkLastChangeAsPersistent: markPersistent,
	} = useDispatch( blockEditorStore );

	useCleanPatternMetadata( { metadata, setAttributes, markQuiet } );

	const blockProps = useBlockProps();
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		value: pattern?.blocks ?? EMPTY_ARRAY,
		onInput: NOOP,
		onChange: NOOP,
	} );

	useLockedDesign( clientId );

	if ( ! pattern ) {
		return (
			<div { ...blockProps }>
				<Warning>
					{ sprintf(
						/* translators: %s: A pattern's slug. */
						__(
							'The pattern "%s" is not available.',
							'pattern-builder'
						),
						slug
					) }
				</Warning>
			</div>
		);
	}

	/**
	 * Puts the pattern's own content back.
	 */
	const resetContent = () => {
		markPersistent();
		setAttributes( { content: undefined } );
	};

	/**
	 * Breaks the link, leaving ordinary editable blocks behind.
	 */
	const detach = () => {
		const detached = applyContent( pattern.blocks, content ).map(
			( block ) => {
				const { patternName, ...rest } =
					block.attributes?.metadata ?? {};

				return cloneBlock(
					patternName
						? {
								...block,
								attributes: {
									...block.attributes,
									metadata: Object.keys( rest ).length
										? rest
										: undefined,
								},
						  }
						: block
				);
			}
		);

		replaceBlocks( clientId, detached );
	};

	return (
		<>
			<BlockControls group="other">
				<ToolbarGroup>
					<ToolbarButton
						onClick={ resetContent }
						disabled={ ! content }
					>
						{ __( 'Reset', 'pattern-builder' ) }
					</ToolbarButton>
					<ToolbarButton onClick={ detach }>
						{ __( 'Detach', 'pattern-builder' ) }
					</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>
			<div { ...innerBlocksProps } />
		</>
	);
}

/**
 * Shown in place of a pattern that contains itself.
 *
 * @return {JSX.Element} The warning.
 */
function RecursionWarning() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Warning>
				{ __(
					'This pattern cannot be rendered inside itself.',
					'pattern-builder'
				) }
			</Warning>
		</div>
	);
}

/**
 * Stops a synced pattern that contains itself from rendering forever.
 *
 * @param {Object} props Block props.
 * @return {JSX.Element} The instance, or a warning.
 */
export function SyncedPatternEditWithRecursionCheck( props ) {
	const { slug } = props.attributes;
	const hasAlreadyRendered = useHasRecursion( slug );

	if ( hasAlreadyRendered ) {
		return <RecursionWarning />;
	}

	return (
		<RecursionProvider uniqueId={ slug }>
			<SyncedPatternEdit { ...props } />
		</RecursionProvider>
	);
}
