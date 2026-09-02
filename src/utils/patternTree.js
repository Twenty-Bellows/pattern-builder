/**
 * A pattern's dependencies: what it places, and what those place.
 *
 * A page pattern is `core/pattern` references to the sections it is built
 * out of, which is what makes it worth sharing and what makes it awkward to
 * carry — the sections have to exist at the far end or the page renders
 * somebody else's placeholder copy. So a collection is a closed world
 * (D38): uploading a pattern uploads the tree below it, installing one
 * installs the tree below it, and every reference names a pattern in the
 * same collection.
 *
 * The walk is here, on its own, because every part of that needs it and
 * none of it needs a network: parse the markup, collect the slugs, resolve
 * them, repeat. `resolve` is supplied by the caller — "give me this pattern
 * by name" is local when uploading and a collection listing when installing
 * — so the same function serves both.
 */

import { parse } from '@wordpress/block-serialization-default-parser';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Walk a parsed tree, innermost blocks included.
 *
 * The raw parser rather than `@wordpress/blocks`: this only needs a block's
 * name and its `slug` attribute, and the raw parser gives both without any
 * block type being registered. `parse()` would answer `core/missing` for
 * `core/pattern` anywhere the block library has not been loaded — which
 * includes every test, and any screen that is not an editor.
 *
 * @param {Array} blocks Parsed blocks.
 * @param {Array} found  Accumulator.
 * @return {Array} Every block in the tree.
 */
function flatten( blocks, found = [] ) {
	for ( const block of blocks ) {
		found.push( block );
		if ( block.innerBlocks?.length ) {
			flatten( block.innerBlocks, found );
		}
	}
	return found;
}

/**
 * The patterns a piece of markup references, in document order.
 *
 * @param {string} content Block markup.
 * @return {string[]} Referenced pattern names, deduplicated.
 */
export function referencesOf( content ) {
	if ( ! content || typeof content !== 'string' ) {
		return [];
	}

	const names = flatten( parse( content ) )
		.filter( ( block ) => block.blockName === 'core/pattern' )
		.map( ( block ) => String( block.attrs?.slug || '' ).trim() )
		.filter( Boolean );

	return [ ...new Set( names ) ];
}

/**
 * The whole tree below a pattern, leaves first.
 *
 * Leaves first is the order everything else needs: an upload has to store a
 * dependency before the pattern that names it, and an install has to write
 * one before the pattern that renders it. The root comes last.
 *
 * A name that `resolve` cannot answer is collected rather than thrown, so a
 * caller can name every missing dependency at once instead of one per
 * attempt. A cycle ends the walk with an error, because there is no order
 * that satisfies it.
 *
 * @param {string}   name    The pattern to start from.
 * @param {Function} resolve Called with a name; returns `{ name, content }`
 *                           or null/undefined when there is no such pattern.
 * @return {{order: Array, missing: string[], cycle: string[]|null}} The tree.
 */
export function treeOf( name, resolve ) {
	const order = [];
	const missing = [];
	const visited = new Set();
	let cycle = null;

	/**
	 * Depth-first, pushing each pattern after everything it needs.
	 *
	 * @param {string}   current The pattern being walked.
	 * @param {string[]} path    The chain that led here, for the cycle report.
	 */
	const walk = ( current, path ) => {
		if ( cycle ) {
			return;
		}

		if ( path.includes( current ) ) {
			cycle = [ ...path.slice( path.indexOf( current ) ), current ];
			return;
		}

		if ( visited.has( current ) ) {
			return;
		}
		visited.add( current );

		const pattern = resolve( current );
		if ( ! pattern ) {
			missing.push( current );
			return;
		}

		for ( const reference of referencesOf( pattern.content ) ) {
			walk( reference, [ ...path, current ] );
		}

		order.push( pattern );
	};

	walk( name, [] );

	return { order, missing, cycle };
}

/**
 * What is wrong with a tree, in a sentence, or '' when nothing is.
 *
 * The upload panel and the abilities both need to say this, and it is the
 * message people will actually meet: referencing a pattern that is not
 * installed is the common mistake, and naming it is the whole of the fix.
 *
 * @param {Object} tree The result of `treeOf`.
 * @return {string} The problem, ready to show.
 */
export function treeProblem( tree ) {
	if ( tree.cycle ) {
		return sprintf(
			/* translators: %s: the chain of pattern names that loops. */
			__(
				'These patterns reference each other in a loop, so there is no order to upload them in: %s',
				'pattern-builder'
			),
			tree.cycle.join( ' → ' )
		);
	}

	if ( tree.missing.length ) {
		return sprintf(
			/* translators: %s: comma-separated list of pattern names. */
			__(
				'This pattern uses patterns that are not on this site: %s',
				'pattern-builder'
			),
			tree.missing.join( ', ' )
		);
	}

	return '';
}

/**
 * Point a pattern's references at a namespace.
 *
 * Uploading never renames a pattern; what changes is the namespace it hangs
 * under, so a reference to `mytheme/hero` becomes `{handle}/{collection}/hero`
 * — the last segment is the pattern's own name and is carried across
 * untouched.
 *
 * Only the `slug` attribute of a `core/pattern` block is rewritten, and it is
 * rewritten in the markup as a string rather than by reserializing the tree:
 * a round trip through `parse()` and `serialize()` would rewrite every block
 * in the pattern to whatever its `save()` writes today, quietly changing
 * markup nobody asked to change.
 *
 * @param {string}   content    Block markup.
 * @param {string}   namespace  The target `{handle}/{collection}`.
 * @param {string[]} references The names to rewrite (from `referencesOf`).
 * @return {string} The markup with its references renamespaced.
 */
export function rewriteReferences( content, namespace, references ) {
	let rewritten = content;

	for ( const reference of references ) {
		const slug = reference.split( '/' ).pop();
		const quoted = reference.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

		rewritten = rewritten.replace(
			new RegExp( `("slug"\\s*:\\s*)"${ quoted }"`, 'g' ),
			`$1"${ namespace }/${ slug }"`
		);
	}

	return rewritten;
}
