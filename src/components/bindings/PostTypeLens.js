import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import { USER_INPUT_ONLY } from './use-binding-sources';

/**
 * Which post type's fields the attribute rows should offer.
 *
 * A pattern binds against a post it has not met, so there is no record to read
 * a field list from; naming a post type supplies the context the sources need.
 * Nothing here is written to the pattern — the binding still resolves against
 * whichever post the pattern is placed in.
 *
 * Only post types with something to bind to are listed. Most of a site's post
 * types have nothing — `wp_template`, `wp_navigation` and the rest register no
 * meta — and offering them promises fields that are not there.
 *
 * @param {Object}    props          Component props.
 * @param {string}    props.value    The post type being listed, or `USER_INPUT_ONLY`.
 * @param {Function}  props.onChange Called with the new post type.
 * @param {?string[]} props.bindable Post types with fields, or `undefined` while loading.
 */
export const PostTypeLens = ( { value, onChange, bindable } ) => {
	/*
	 * `getPostTypes` returns only `show_in_rest` types; `bindable` narrows those
	 * to the ones with fields. Filtering further by `viewable` would drop a
	 * custom post type that is not publicly queryable but does register meta
	 * worth binding to.
	 */
	const postTypes = useSelect(
		( select ) => {
			const types = select( coreStore ).getPostTypes( { per_page: -1 } );

			return ( types || [] )
				.filter( ( type ) => ( bindable || [] ).includes( type.slug ) )
				.map( ( type ) => ( {
					label: type.labels?.singular_name || type.name || type.slug,
					value: type.slug,
				} ) );
		},
		[ bindable ]
	);

	const options = [
		{
			label: __( 'User input only', 'pattern-builder' ),
			value: USER_INPUT_ONLY,
		},
		...postTypes,
	];

	return (
		<SelectControl
			label={ __( 'Fields from', 'pattern-builder' ) }
			value={ value }
			options={ options }
			onChange={ onChange }
			help={ __(
				'Choose a Post Type this pattern will be used in to create bindings to its data.',
				'pattern-builder'
			) }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};

export default PostTypeLens;
