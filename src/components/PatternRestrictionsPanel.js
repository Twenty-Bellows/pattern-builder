import { FormTokenField } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';

export const PatternRestrictionsPanel = ({ patternPost }) => {

	const allBlockTypes = useSelect(
		(select) => select(blocksStore).getBlockTypes().map((blockType) => (blockType.name)),
		[]
	);

	const [blockTypes, setBlockTypes] = useState(patternPost.wp_pattern_block_types || []);

	const changeBlockTypes = (value) => {
		setBlockTypes(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_block_types: value });
	};

	return (
		<FormTokenField
			__experimentalShowHowTo={false}
			label="Block Types"
			value={blockTypes}
			suggestions={allBlockTypes}
			tokenizeOnBlur
			onChange={(value) => changeBlockTypes( value )}
		/>

	);
}
