import { __ } from '@wordpress/i18n';
import {
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { getBlockType } from '@wordpress/blocks';
import { Card, CardBody, Panel, PanelBody } from '@wordpress/components';
import { BlockIcon } from '@wordpress/block-editor';
import {
	ToggleControl,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalText as Text
} from '@wordpress/components';
import { useState } from '@wordpress/element';

// NOTE: this was retrieved from the packages/patterns/src/constants.js file in Gutenberg.
// They are correct.  This should not be hardcoded.  Nor should it be inacessible...

// TODO: This should not be hardcoded. Maybe there should be a config and/or an UI.
export const PARTIAL_SYNCING_SUPPORTED_BLOCKS = {
	'core/paragraph': [ 'content' ],
	'core/heading': [ 'content' ],
	'core/button': [ 'text', 'url', 'linkTarget', 'rel' ],
	'core/image': [ 'id', 'url', 'title', 'alt' ],
};

const BindableBlockControls = ({block}) => {

	const { updateBlockAttributes } = useDispatch('core/block-editor');

	const blockType = getBlockType(block.name);

	const [blockName, setBlockName] = useState(block.attributes?.metadata?.name || '');

	const [blockBindable, setBlockBindable] = useState(block.attributes?.metadata?.bindings?.__default?.source === 'core/pattern-overrides' || false);

	const [blockCanBeBound, setBlockCanBeBound] = useState(blockName !== '');

	const updateBlockName = (newName) => {

		setBlockName(newName);

		const blockCanBeBound = newName !== '';

		setBlockCanBeBound(blockCanBeBound);

		if (blockBindable && ! blockCanBeBound) {
			setBlockBindable(false);
		}

		updateBlockAttributes(block.clientId, {
			metadata: {
				...block.attributes.metadata,
				name: newName,
			},
		});
	}

	const updateBlockBindable = (newValue) => {
		setBlockBindable(newValue);
		updateBlockAttributes(block.clientId, {
			metadata: {
				...block.attributes.metadata,
				bindings: {},
			},
		});
	}

	return (
		<Card style={{ marginBottom: '1rem' }}>
			<CardBody style={{gap: "0.5rem", display: 'flex', flexDirection: 'column'}}>
				<div style={{ display: 'flex', alignItems: 'center', justifyContent: 'left' }}>
					<BlockIcon icon={blockType.icon} />
					<Text>{blockType.title}</Text>
				</div>

				<TextControl
					placeholder="Name this block to enable binding..."
					label="Block Name"
					value={blockName || ''}
					onChange={updateBlockName}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<ToggleControl
					disabled={!blockCanBeBound}
					label="Bindable"
					checked={blockBindable}
					onChange={updateBlockBindable}
					__nextHasNoMarginBottom
				/>
			</CardBody>
		</Card>
	);
}

const BlockBindingsPanel = () => {

	function getBindableBlocks(blocks) {
		return blocks.reduce((acc, block) => {

			// Check if the block is bindable
			if (PARTIAL_SYNCING_SUPPORTED_BLOCKS[block.name]) {
				acc.push(block);
			}

			// Recursively check inner blocks
			if (block.innerBlocks && block.innerBlocks.length > 0) {
				acc.push(...getBindableBlocks(block.innerBlocks));
			}

			return acc;
		}
		, []);
	}

	const bindableBlocks = useSelect(
		(select) => {
			const rootBlocks = select(blockEditorStore).getBlocks();

			// dig through the blocks to find anything in the tree that is bindable
			const bindableBlocks = getBindableBlocks(rootBlocks);
			return bindableBlocks;
		},
		[]
	);

	return (
		<Panel header={__('Block Binding Overrides', 'pattern-manager')}>
			<PanelBody>

			{bindableBlocks.length > 0 ? (
				<div className="block-bindings-list">

					<p style={{marginTop:0}}>The following blocks can allow user changes throughout instances of this pattern. This is only available to 'synced' patterns.</p>
					<p>Name the block to allow binding. This is the name a user will see when they use the pattern.</p>

					{bindableBlocks.map((block) => (
						<BindableBlockControls key={block.clientId} block={block} />
					))}
				</div>
			) : (
				<p>No bindable blocks found.</p>
			)}
			</PanelBody>
		</Panel>
	);
}

export default BlockBindingsPanel;
