/**
 * A block context with the post taken out of it.
 *
 * @param {Object} context The context the editor supplied.
 * @return {Object} The same context, without `postId` and `postType`.
 */
export function withoutPost( context ) {
	const { postId, postType, ...rest } = context ?? {};

	return rest;
}
