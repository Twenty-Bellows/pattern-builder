import { __ } from '@wordpress/i18n';
import {
	FormTokenField,
	TextareaControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalNumberControl as NumberControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';

/**
 * Edits a theme pattern's descriptive metadata.
 *
 * Description, categories, keywords, and viewport width all round-trip
 * through the pattern file's header. Edits stage on the pb_pattern entity and
 * persist with the next save.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 */
export const PatternMetadataPanel = ( { patternPost } ) => {
	const [ description, setDescription ] = useState(
		patternPost.description || ''
	);
	const [ categories, setCategories ] = useState(
		patternPost.categories || []
	);
	const [ keywords, setKeywords ] = useState( patternPost.keywords || [] );
	const [ viewportWidth, setViewportWidth ] = useState(
		patternPost.viewportWidth || ''
	);

	const stageEdit = ( edit ) => {
		dispatch( 'core' ).editEntityRecord(
			'postType',
			'pb_pattern',
			patternPost.id,
			edit
		);
	};

	return (
		<VStack spacing={ 4 }>
			<VStack spacing={ 0 }>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description', 'pattern-builder' ) }
					value={ description }
					rows={ 3 }
					onChange={ ( value ) => {
						setDescription( value );
						stageEdit( { description: value } );
					} }
				/>
				<Text variant="muted">
					{ __(
						'Shown in the inserter and read by assistive technology.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<FormTokenField
					__experimentalShowHowTo={ false }
					label={ __( 'Categories', 'pattern-builder' ) }
					value={ categories }
					tokenizeOnBlur
					onChange={ ( value ) => {
						setCategories( value );
						stageEdit( { categories: value } );
					} }
				/>
				<Text variant="muted">
					{ __(
						'Pattern categories group patterns in the inserter.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<FormTokenField
					__experimentalShowHowTo={ false }
					label={ __( 'Keywords', 'pattern-builder' ) }
					value={ keywords }
					tokenizeOnBlur
					onChange={ ( value ) => {
						setKeywords( value );
						stageEdit( { keywords: value } );
					} }
				/>
				<Text variant="muted">
					{ __(
						'Extra terms the inserter search matches.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
			<VStack spacing={ 0 }>
				<NumberControl
					__next40pxDefaultSize
					label={ __( 'Viewport Width', 'pattern-builder' ) }
					value={ viewportWidth }
					min={ 0 }
					onChange={ ( value ) => {
						setViewportWidth( value );
						stageEdit( {
							viewportWidth: value ? parseInt( value, 10 ) : null,
						} );
					} }
				/>
				<Text variant="muted">
					{ __(
						'The width, in pixels, the pattern is previewed at in the inserter.',
						'pattern-builder'
					) }
				</Text>
			</VStack>
		</VStack>
	);
};
