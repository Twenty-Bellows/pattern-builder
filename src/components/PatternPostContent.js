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
 * The editor gives a document's blocks a `postId` and `postType` context unless its post
 * type is one of core's `NON_CONTEXTUAL_POST_TYPES` — `wp_block`, `wp_navigation`,
 * `wp_template_part`. A user pattern is on that list, and a theme pattern cannot be: the
 * list is a constant inside core's editor bundle with no filter over it.
 *
 * So `core/post-content` in a theme pattern is handed the pattern's own id, finds that id
 * already on the recursion stack — the editor wraps the document in a `RecursionProvider`
 * keyed on it — and renders "Block cannot be rendered inside itself" in place of anything
 * editable. Taking the two values away puts the block on the path core's own pattern
 * editor uses, where it draws its placeholder instead.
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
