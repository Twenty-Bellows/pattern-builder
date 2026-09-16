import { __ } from '@wordpress/i18n';
import { BlockIcon, store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import {
	Card,
	CardBody,
	ToggleControl,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
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
	getBindingMode,
	needsRows,
	withBindings,
} from '../../utils/bindings';
import { AttributeBindingRow } from './AttributeBindingRow';

/**
 * One bindable block, and where each of its values comes from.
 *
 * With no post type chosen a block has only the one choice a pattern can make
 * on its own, so it gets a toggle. Choosing a post type turns on a row per
 * bindable attribute, which can additionally reach that post type's fields. A
 * block whose bindings are too detailed for the toggle keeps its rows either
 * way, so nothing already bound is hidden.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.block     The block from the editor store.
 * @param {string[]} props.supported The attributes it can bind.
 * @param {boolean}  props.showRows  Whether a post type is being listed.
 * @param {Array}    props.sources   The registered binding sources.
 */
export const BindableBlockCard = ( {
	block,
	supported,
	showRows,
	sources,
} ) => {
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const blockType = getBlockType( block.name );
	const metadata = block.attributes?.metadata;
	const bindings = metadata?.bindings;
	const name = metadata?.name || '';
	const rows = expandBindings( bindings, supported );
	const perAttribute = showRows || needsRows( bindings, supported );

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
						spellCheck={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>

					{ perAttribute ? (
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
									sources={ sources }
									canOverride={ !! name }
									onChange={ ( binding ) =>
										changeRow( attribute, binding )
									}
								/>
							) ) }
						</div>
					) : (
						<ToggleControl
							label={ __( 'Overridable', 'pattern-builder' ) }
							checked={
								getBindingMode( bindings, supported ) ===
								MODE.OVERRIDES
							}
							disabled={ ! name }
							onChange={ ( on ) =>
								changeMode( on ? MODE.OVERRIDES : MODE.STATIC )
							}
							__nextHasNoMarginBottom
						/>
					) }
				</VStack>
			</CardBody>
		</Card>
	);
};

export default BindableBlockCard;
