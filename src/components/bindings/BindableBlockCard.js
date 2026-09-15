import { __ } from '@wordpress/i18n';
import { BlockIcon, store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import {
	Card,
	CardBody,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';

import {
	MODE,
	applyRows,
	bindEverything,
	bindNothing,
	expandBindings,
	getAttributeType,
	withBindings,
} from '../../utils/bindings';
import { AttributeBindingRow } from './AttributeBindingRow';

/**
 * One bindable block, and where each of its values comes from.
 *
 * The three modes are not three kinds of binding. Overridable is the shorthand
 * that writes `__default`, the way this panel always has; Dynamic is the same
 * choices opened up one attribute at a time, with every registered source
 * alongside them. Nothing is lost moving between the two — an all-overridable
 * block collapses back to `__default` on its own.
 *
 * @param {Object}   props                  Component props.
 * @param {Object}   props.block            The block from the editor store.
 * @param {string[]} props.supported        The attributes it can bind.
 * @param {string}   props.mode             The mode the panel is showing.
 * @param {Array}    props.listed           Sources that published fields.
 * @param {Array}    props.opaque           Sources that cannot describe
 *                                          themselves.
 * @param {Function} props.onExpandedChange Called when the author opens or
 *                                          closes the per-attribute view.
 */
export const BindableBlockCard = ( {
	block,
	supported,
	mode,
	listed,
	opaque,
	onExpandedChange,
} ) => {
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const blockType = getBlockType( block.name );
	const metadata = block.attributes?.metadata;
	const bindings = metadata?.bindings;
	const name = metadata?.name || '';
	const rows = expandBindings( bindings, supported );

	const writeBindings = ( next ) => {
		updateBlockAttributes( block.clientId, {
			metadata: withBindings( metadata, next ),
		} );
	};

	const changeName = ( value ) => {
		const next = { ...( metadata || {} ) };

		if ( value ) {
			next.name = value;
		} else {
			delete next.name;
		}

		updateBlockAttributes( block.clientId, {
			metadata: Object.keys( next ).length > 0 ? next : undefined,
		} );
	};

	const changeMode = ( next ) => {
		if ( next === MODE.DYNAMIC ) {
			onExpandedChange( true );
			return;
		}

		onExpandedChange( false );
		writeBindings(
			next === MODE.OVERRIDES
				? bindEverything( bindings, supported )
				: bindNothing( bindings, supported )
		);
	};

	const changeRow = ( attribute, binding ) => {
		const next = { ...rows };

		if ( binding ) {
			next[ attribute ] = binding;
		} else {
			delete next[ attribute ];
		}

		writeBindings( applyRows( bindings, next, supported ) );
	};

	return (
		<Card size="small">
			<CardBody>
				<VStack spacing={ 3 }>
					<div className="pattern-builder-bindings__block">
						<BlockIcon icon={ blockType?.icon } />
						<Text>{ blockType?.title || block.name }</Text>
					</div>

					<TextControl
						label={ __( 'Block name', 'pattern-builder' ) }
						placeholder={ __(
							'Name this block…',
							'pattern-builder'
						) }
						value={ name }
						onChange={ changeName }
						help={
							name
								? undefined
								: __(
										'A name is what a pattern override is stored against, so overrides need one. A binding to another source does not.',
										'pattern-builder'
								  )
						}
						spellCheck={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>

					<ToggleGroupControl
						label={ __( 'Value from', 'pattern-builder' ) }
						value={ mode }
						onChange={ changeMode }
						isBlock
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption
							value={ MODE.STATIC }
							label={ __( 'Static', 'pattern-builder' ) }
						/>
						<ToggleGroupControlOption
							value={ MODE.OVERRIDES }
							label={ __( 'Overridable', 'pattern-builder' ) }
							disabled={ ! name }
						/>
						<ToggleGroupControlOption
							value={ MODE.DYNAMIC }
							label={ __( 'Dynamic', 'pattern-builder' ) }
						/>
					</ToggleGroupControl>

					{ mode === MODE.DYNAMIC && (
						<div className="pattern-builder-bindings__rows">
							{ supported.map( ( attribute ) => (
								<AttributeBindingRow
									key={ attribute }
									attribute={ attribute }
									type={ getAttributeType(
										blockType,
										attribute
									) }
									binding={ rows[ attribute ] }
									listed={ listed }
									opaque={ opaque }
									canOverride={ !! name }
									onChange={ ( binding ) =>
										changeRow( attribute, binding )
									}
								/>
							) ) }
						</div>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
};

export default BindableBlockCard;
