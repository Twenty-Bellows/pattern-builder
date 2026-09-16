/**
 * A block context with the post taken out of it.
 *
 * Only what is there is removed, so a context that never carried a post comes back
 * unchanged and the caller does not have to know which it had.
 *
 * @param {Object} context The context the editor supplied.
 * @return {Object} The same context, without `postId` and `postType`.
 */
export function withoutPost( context ) {
	const { postId, postType, ...rest } = context ?? {};

	return rest;
}
