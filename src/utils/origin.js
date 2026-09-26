/**
 * What a pattern's `Origin:` can name.
 */

/**
 * The scheme an origin carries when it names something that is not a pattern on the cloud.
 * An origin with a scheme is local to this site and never travels with an upload; a cloud
 * name has none, and is the only kind the service can resolve to a page. A colon cannot
 * occur in a cloud name, whose every segment is a slug, so the two can never be mistaken for
 * one another.
 *
 * Mirrors `Pattern_File_Store::ORIGIN_PLUGIN_SCHEME`.
 */
export const PLUGIN_ORIGIN_SCHEME = 'plugin:';
