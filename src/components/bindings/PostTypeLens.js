import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import { USER_INPUT_ONLY } from './use-binding-sources';

/**
 * The help text below the lens.
 *
 * @param {string}  value      The post type being listed, or `USER_INPUT_ONLY`.
 * @param {boolean} isDeclared Whether the pattern declares that post type.
 * @return {string} The help text.
 */
function helpText( value, isDeclared ) {
	if ( ! value ) {
		return __(
			'Blocks can only take their value from the pattern file or from whoever places the pattern. Choose a post type to bind them to its fields as well.',
			'pattern-builder'
		);
	}

	if ( isDeclared ) {
		return __(
			'This pattern is associated with this post type. Bindings resolve against whichever post it lands in; this only chooses which fields are offered below.',
			'pattern-builder'
		);
	}

	return __(
		'Bindings resolve against whichever post the pattern lands in. This only chooses which fields are offered below — it is not saved to the pattern.',
		'pattern-builder'
	);
}

/**
 * Which post type's fields the attribute rows should offer.
 *
 * A pattern binds against a post it has not met, so there is no record to read
 * a field list from; naming a post type supplies the context the sources need.
 * Nothing here is written to the pattern — the binding still resolves against
 * whichever post the pattern is placed in.
 *
 * @param {Object}   props                  Component props.
 * @param {string}   props.value            The post type being listed, or `USER_INPUT_ONLY`.
 * @param {Function} props.onChange         Called with the new post type.
 * @param {string[]} props.patternPostTypes The pattern's declared post types.
 */
export const PostTypeLens = ( { value, onChange, patternPostTypes } ) => {
	/*
	 * `getPostTypes` returns only `show_in_rest` types, which is already the
	 * gate that matters for bindings. Filtering further by `viewable` would
	 * drop a custom post type that is not publicly queryable but does register
	 * meta worth binding to.
	 */
	const postTypes = useSelect( ( select ) => {
		const types = select( coreStore ).getPostTypes( { per_page: -1 } );

		return ( types || [] ).map( ( type ) => ( {
			label: type.labels?.singular_name || type.name || type.slug,
			value: type.slug,
		} ) );
	}, [] );

	const options = [
		{
			label: __( 'User input only', 'pattern-builder' ),
			value: USER_INPUT_ONLY,
		},
		...postTypes,
	];

	const isDeclared = ( patternPostTypes || [] ).includes( value );

	return (
		<SelectControl
			label={ __( 'Fields from', 'pattern-builder' ) }
			value={ value }
			options={ options }
			onChange={ onChange }
			help={ helpText( value, isDeclared ) }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};

export default PostTypeLens;
