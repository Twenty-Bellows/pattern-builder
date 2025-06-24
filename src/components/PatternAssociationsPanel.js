import { __, _x } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { FormTokenField, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';

export const PatternAssociationsPanel = ({ patternPost }) => {

	const allBlockTypes = useSelect(
		(select) => select(blocksStore).getBlockTypes().map((blockType) => (blockType.name)),
		[]
	);

	const allPostTypes = [];

	const allTemplateTypes = [
		'index',
		'home',
		'front-page',
		'singular',
		'single',
		'page',
		'archive',
		'author',
		'category',
		'taxonomy',
		'date',
		'tag',
		'attachment',
		'search',
		'privacy-policy',
		'404',
	];

	const [blockTypes, setBlockTypes] = useState(patternPost.wp_pattern_block_types || []);
	const [postTypes, setPostTypes] = useState(patternPost.wp_pattern_post_types || []);
	const [templateTypes, setTemplateTypes] = useState(patternPost.wp_pattern_template_types || []);
	const [patternInserter, setPatternInserter] = useState( patternPost.wp_pattern_inserter !== 'no' );

	const changeBlockTypes = (value) => {
		setBlockTypes(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_block_types: value });
	};

	const changePostTypes = (value) => {
		setPostTypes(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_post_types: value });
	};

	const changeTemplateTypes = (value) => {
		setTemplateTypes(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_template_types: value });
	};

	const changePatternInserter = (value) => {
		setPatternInserter(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_inserter: value ? 'yes' : 'no' });
	}

	return (<VStack spacing={4}>
		<Text>
			{__(
				'These values are useful for building ',
				'pattern-builder'
			)}
			<a href="https://developer.wordpress.org/themes/patterns/starter-patterns/" target="_blank" rel="noopener noreferrer">
				{__('Starter Patterns', 'pattern-builder')}
			</a>
			{__(
				' that can be used in specific contexts.',
				'pattern-builder'
			)}
		</Text>
		<VStack spacing={0}>
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
					'Assign the blocks that this pattern should be used in.',
					'pattern-builder'
				)}
			</Text>
		</VStack>
		<VStack spacing={0}>
			<FormTokenField
				__experimentalShowHowTo={false}
				label="Post Types"
				value={postTypes}
				suggestions={allPostTypes}
				tokenizeOnBlur
				onChange={(value) => changePostTypes(value)}
			/>
			<Text variant="muted">
				{__(
					'Assign the post types that this pattern should be used in.',
					'pattern-builder'
				)}
			</Text>
		</VStack>
		<VStack spacing={0}>
			<FormTokenField
				__experimentalShowHowTo={false}
				label="Template Types"
				value={templateTypes}
				suggestions={allTemplateTypes}
				tokenizeOnBlur
				onChange={(value) => changeTemplateTypes(value)}
			/>
			<Text variant="muted">
				{__(
					'Assign the template types that this pattern should be used in.',
					'pattern-builder'
				)}
			</Text>
		</VStack>
		<VStack spacing={0}>
			<ToggleControl
				label={'Available in Inserter'}
				checked={patternInserter}
				onChange={(value) => {
					changePatternInserter(value);
				}}
			/>
			<Text variant="muted">
				{__(
					'If true, this pattern will be available in the block inserter.',
					'pattern-builder'
				)}
			</Text>
		</VStack>
	</VStack>);
}
