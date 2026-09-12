/**
 * Where an edit inside a pattern instance is stored.
 */

/**
 * Works out the content a pattern block should hold after an edit.
 *
 * @param {Object} options                 Options.
 * @param {string} [options.name]          The edited block's `metadata.name`.
 * @param {string} [options.hostBlockName] Block name of the nearest content host.
 * @param {Object} options.bindings        The bindings being written, keyed by attribute.
 * @param {Object} [options.content]       The host's current content.
 * @return {Object|null} The host's new content, or null to leave it to core.
 */
export function getOverridesUpdate( {
	name,
	hostBlockName,
	bindings,
	content,
} ) {
	if ( ! name || hostBlockName !== 'core/pattern' ) {
		return null;
	}

	const values = Object.entries( bindings ).reduce(
		( carry, [ attribute, { newValue } ] ) => {
			carry[ attribute ] = newValue === undefined ? '' : newValue;

			return carry;
		},
		{}
	);

	return {
		...content,
		[ name ]: { ...content?.[ name ], ...values },
	};
}
