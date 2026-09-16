/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { withoutPost } from '../utils/blockContext';

/**
 * The post type this applies to: Pattern Builder's file-backed theme patterns.
 */
const PATTERN_POST_TYPE = 'pb_pattern';

/**
 * `core/post-content` while a theme pattern is open.
 *
 * @param {Object}   root0           Component props.
 * @param {Function} root0.BlockEdit The wrapped edit.
 */
function PostContentInPattern( { BlockEdit, ...props } ) {
	const isPattern = useSelect(
		( select ) =>
			select( editorStore )?.getCurrentPostType() === PATTERN_POST_TYPE,
		[]
	);

	const context = useMemo(
		() => ( isPattern ? withoutPost( props.context ) : props.context ),
		[ isPattern, props.context ]
	);

	return <BlockEdit { ...props } context={ context } />;
}

const withPatternPostContent = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		// This bundle loads on every block editor screen, so the block is settled here,
		// where no hook runs, and the post type inside, where one may.
		if ( props.name !== 'core/post-content' ) {
			return <BlockEdit { ...props } />;
		}

		return <PostContentInPattern BlockEdit={ BlockEdit } { ...props } />;
	},
	'withPatternPostContent'
);

/**
 * Registers the filter. Removing only what is there means this stops mattering by itself
 * if core ever adds the post type to that list.
 */
export function registerPatternPostContent() {
	addFilter(
		'editor.BlockEdit',
		'pattern-builder/pattern-post-content',
		withPatternPostContent
	);
}
