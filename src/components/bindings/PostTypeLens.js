import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Which post type's fields the pickers should offer.
 *
 * A pattern binds against a post it has not met yet, so there is no record to
 * read a field list from — which is why core's own bindings panel is always
 * empty here. Naming a post type supplies the one piece of context the sources
 * actually need, and `core/post-meta` asks for nothing else.
 *
 * This is a lens, not data. It changes what the pickers offer and nothing in
 * the pattern file; the binding still resolves against whichever post the
 * pattern is placed in. It starts on the pattern's own `Post Types` header
 * when it has one, because that is the author's own statement of where the
 * pattern is meant to be used.
 *
 * @param {Object}   props                  Component props.
 * @param {string}   props.value            The post type being listed.
 * @param {Function} props.onChange         Called with the new post type.
 * @param {string[]} props.patternPostTypes The pattern's declared post types.
 */
export const PostTypeLens = ( { value, onChange, patternPostTypes } ) => {
	const postTypes = useSelect( ( select ) => {
		const types = select( coreStore ).getPostTypes( { per_page: -1 } );

		return ( types || [] )
			.filter( ( type ) => type.viewable )
			.map( ( type ) => ( {
				label: type.labels?.singular_name || type.name || type.slug,
				value: type.slug,
			} ) );
	}, [] );

	/*
	 * Until the post types load — and if the pattern names one that is not
	 * public — the current value still needs an option to sit in, or the
	 * select renders blank against a value it is holding.
	 */
	const options = postTypes.some( ( option ) => option.value === value )
		? postTypes
		: [ { label: value, value }, ...postTypes ];

	const isDeclared = ( patternPostTypes || [] ).includes( value );

	return (
		<SelectControl
			label={ __( 'Fields from', 'pattern-builder' ) }
			value={ value }
			options={ options }
			onChange={ onChange }
			help={
				isDeclared
					? __(
							'This pattern is associated with this post type. Bindings resolve against whichever post the pattern lands in; this only changes which fields are offered below.',
							'pattern-builder'
					  )
					: __(
							'Bindings resolve against whichever post the pattern lands in. This only changes which fields are offered below — it is not saved to the pattern.',
							'pattern-builder'
					  )
			}
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};

export default PostTypeLens;
