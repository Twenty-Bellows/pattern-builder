import { __, _x } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';

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

	return (<>
		<FormTokenField
			__experimentalShowHowTo={false}
			label="Block Types"
			value={blockTypes}
			suggestions={allBlockTypes}
			tokenizeOnBlur
			onChange={(value) => changeBlockTypes(value)}
		/>
		<Text variant="muted">
			{__(
				'Limit the blocks that this pattern can be used in. This is useful for patterns that are designed to work with specific blocks only - especially core/post-content.',
				'pattern-builder'
			)}
		</Text>

	</>);
}
