import { __ } from '@wordpress/i18n';
import {
	FormTokenField,
	TextControl,
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
 * Edits a pattern's name, slug, and descriptive metadata.
 *
 * For theme patterns these round-trip through the pattern file's header —
 * renaming the slug rewrites the file. Edits stage on the entity and persist
 * with the next save.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 * @param {string} root0.postType    'pb_pattern' or 'wp_block'.
 */
export const PatternMetadataPanel = ( {
	patternPost,
	postType = 'pb_pattern',
} ) => {
	const isThemePattern = postType === 'pb_pattern';
	const rawTitle =
		typeof patternPost.title === 'object'
			? patternPost.title?.raw || ''
			: patternPost.title || '';
	// Theme patterns carry a namespaced name; only the slug half is editable.
	const namespace = isThemePattern
		? String( patternPost.name || '' ).split( '/' )[ 0 ]
		: '';
	const currentSlug = isThemePattern
		? String( patternPost.name || '' )
				.split( '/' )
				.slice( 1 )
				.join( '/' )
		: patternPost.slug || '';

	const [ title, setTitle ] = useState( rawTitle );
	const [ slug, setSlug ] = useState( currentSlug );
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
			postType,
			patternPost.id,
			edit
		);
	};

	return (
		<VStack spacing={ 4 }>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Name', 'pattern-builder' ) }
				value={ title }
				onChange={ ( value ) => {
					setTitle( value );
					stageEdit( { title: value } );
				} }
			/>
			<VStack spacing={ 0 }>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Slug', 'pattern-builder' ) }
					value={ slug }
					onChange={ ( value ) => {
						const clean = value
							.toLowerCase()
							.replace( /[^a-z0-9-]+/g, '-' );
						setSlug( clean );
						stageEdit(
							isThemePattern
								? { name: `${ namespace }/${ clean }` }
								: { slug: clean }
						);
					} }
				/>
				<Text variant="muted">
					{ isThemePattern
						? __(
								'The pattern’s identifier in the theme; renaming it rewrites the pattern file.',
								'pattern-builder'
						  )
						: __(
								'The pattern’s identifier on this site.',
								'pattern-builder'
						  ) }
				</Text>
			</VStack>
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
