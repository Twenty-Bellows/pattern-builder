/**
 * Knows which theme patterns want inserted copies kept linked.
 *
 * The slugs are printed by whichever plugin provides the pattern runtime:
 * Synced Patterns for Themes when it is active, this plugin otherwise. Both
 * read the same `Synced: yes` pattern-file header, so the answer is the same
 * either way — this just reads whichever global made it to the page.
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
