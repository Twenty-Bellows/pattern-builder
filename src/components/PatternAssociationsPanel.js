import { __ } from '@wordpress/i18n';
import {
	FormTokenField,
	ToggleControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch, useSelect } from '@wordpress/data';
import { store as blocksStore } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';

import { TEMPLATE_TYPES } from './patternKinds';

const ALL_TEMPLATE_TYPES = TEMPLATE_TYPES.map( ( type ) => type.slug );

/**
 * Edits a theme pattern's contextual associations.
 *
 * These are the pattern-file headers WordPress reads to offer a pattern in
 * specific contexts — block types, post types, template types — plus whether
 * the pattern appears in the inserter at all. All of them stage edits on the
 * pb_pattern entity and persist with the next save.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 */
export const PatternAssociationsPanel = ( { patternPost } ) => {
	const allBlockTypes = useSelect(
		( select ) =>
			select( blocksStore )
				.getBlockTypes()
				.map( ( blockType ) => blockType.name ),
		[]
	);

	const allPostTypes = useSelect( ( select ) => {
		const types = select( coreStore ).getPostTypes( { per_page: -1 } );
		return ( types || [] )
			.filter( ( type ) => type.viewable )
			.map( ( type ) => type.slug );
	}, [] );

	const [ blockTypes, setBlockTypes ] = useState(
		patternPost.blockTypes || []
	);
	const [ postTypes, setPostTypes ] = useState( patternPost.postTypes || [] );
	const [ templateTypes, setTemplateTypes ] = useState(
		patternPost.templateTypes || []
	);
	const [ patternInserter, setPatternInserter ] = useState(
		patternPost.inserter !== false
	);

	const stageEdit = ( edit ) => {
		dispatch( 'core' ).editEntityRecord(
			'postType',
			'pb_pattern',
			patternPost.id,
			edit
		);
	};

	const changeBlockTypes = ( value ) => {
		setBlockTypes( value );
		stageEdit( { blockTypes: value } );
	};

	const changePostTypes = ( value ) => {
		setPostTypes( value );
		stageEdit( { postTypes: value } );
	};

	const changeTemplateTypes = ( value ) => {
		setTemplateTypes( value );
		stageEdit( { templateTypes: value } );
	};

	const changePatternInserter = ( value ) => {
		setPatternInserter( value );
		stageEdit( { inserter: !! value } );
	};

	return (
		<VStack spacing={ 4 }>
			<Text>
				{ __(
					'These values are useful for building',
					'pattern-builder'
				) }
				<a
					href="https://developer.wordpress.org/themes/patterns/starter-patterns/"
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Starter Patterns', 'pattern-builder' ) }
				</a>
				{ __(
					'that can be used in specific contexts.',
					'pattern-builder'
				) }
			</Text>
			<VStack spacing={ 0 }>
				<FormTokenField
					__experimentalShowHowTo={ false }
					label={ __( 'Block Types', 'pattern-builder' ) }
					value={ blockTypes }
					suggestions={ allBlockTypes }
					tokenizeOnBlur
					onChange={ ( value ) => changeBlockTypes( value ) }
				/>
				<Text variant="muted">
					{ __(
						'Assign the blocks that this pattern should be used in.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<FormTokenField
					__experimentalShowHowTo={ false }
					label={ __( 'Post Types', 'pattern-builder' ) }
					value={ postTypes }
					suggestions={ allPostTypes }
					tokenizeOnBlur
					onChange={ ( value ) => changePostTypes( value ) }
				/>
				<Text variant="muted">
					{ __(
						'Assign the post types that this pattern should be used in.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<FormTokenField
					__experimentalShowHowTo={ false }
					label={ __( 'Template Types', 'pattern-builder' ) }
					value={ templateTypes }
					suggestions={ ALL_TEMPLATE_TYPES }
					tokenizeOnBlur
					onChange={ ( value ) => changeTemplateTypes( value ) }
				/>
				<Text variant="muted">
					{ __(
						'Assign the template types that this pattern should be used in.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<ToggleControl
					label={ __( 'Available in Inserter', 'pattern-builder' ) }
					checked={ patternInserter }
					onChange={ ( value ) => {
						changePatternInserter( value );
					} }
				/>
				<Text variant="muted">
					{ __(
						'If true, this pattern will be available in the block inserter.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
		</VStack>
	);
};
