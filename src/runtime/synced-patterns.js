/**
 * Knows which theme patterns want inserted copies kept linked.
 */

/**
 * The synced pattern slugs the runtime provider printed.
 *
 * @return {string[]} Pattern slugs.
 */
function getSyncedPatternSlugs() {
	return (
		window.syncedPatternsForThemes?.syncedPatterns ??
		window.patternBuilder?.syncedPatterns ??
		[]
	);
}

/**
 * Whether a pattern is inserted as a reference to itself.
 *
 * @param {string} slug Pattern slug, including namespace.
 * @return {boolean} Whether the pattern is synced.
 */
export function isSyncedPattern( slug ) {
	const slugs = getSyncedPatternSlugs();

	return Array.isArray( slugs ) && slugs.includes( slug );
}
