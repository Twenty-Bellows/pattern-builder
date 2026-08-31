/**
 * Makes bound content resolve in the browse screen's previews.
 *
 * A pattern that fills another pattern's slots carries the words in the
 * reference's `content` attribute, and the blocks inside read them through
 * the `core/pattern-overrides` binding source. WordPress splits that source
 * in two: the server registers a stub (name, label, the context it reads)
 * on every editor screen, and the editor packages layer the working half
 * — `getValues`, `setValues` — on top of it while booting, through a
 * private API no plugin can call.
 *
 * This screen never boots an editor, so it only ever has the stub. A bound
 * attribute with no `getValues` behind it renders the source's *label* in
 * place of the words, which is why every slot in the grid read "Pattern
 * Overrides".
 *
 * So the read half is registered here. It is core's own `getValues`, and
 * only that one: nothing on this screen edits a block, so there is no
 * `setValues` to write back through. If a future WordPress registers the
 * working source everywhere, this stands aside.
 */

import {
	getBlockBindingsSource,
	registerBlockBindingsSource,
} from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';

const SOURCE_NAME = 'core/pattern-overrides';

/**
 * The value each bound attribute takes inside an instance.
 *
 * Core's own implementation, kept identical: the reference's `content`
 * arrives as `pattern/overrides` context, keyed by the slot names the
 * design pattern declared.
 *
 * @param {Object}   args          Source arguments.
 * @param {Function} args.select   Data registry select.
 * @param {string}   args.clientId The bound block's client ID.
 * @param {Object}   args.context  Block context.
 * @param {Object}   args.bindings The block's bindings.
 * @return {Object} Values keyed by attribute name.
 */
export function getPatternOverrideValues( {
	select,
	clientId,
	context,
	bindings,
} ) {
	const overrides = context[ 'pattern/overrides' ];
	const attributes =
		select( blockEditorStore ).getBlockAttributes( clientId );
	const values = {};

	for ( const attribute of Object.keys( bindings ) ) {
		const supplied =
			overrides?.[ attributes?.metadata?.name ]?.[ attribute ];

		// Nothing supplied leaves the pattern's own content in place; an
		// empty string is a slot the page cleared, and undefined is how a
		// block renders as empty.
		if ( undefined === supplied ) {
			values[ attribute ] = attributes?.[ attribute ];
		} else {
			values[ attribute ] = '' === supplied ? undefined : supplied;
		}
	}

	return values;
}

/**
 * Registers the read half of the source, unless an editor already did.
 */
export function registerPreviewBindings() {
	const source = getBlockBindingsSource( SOURCE_NAME );

	if ( typeof source?.getValues === 'function' ) {
		return;
	}

	registerBlockBindingsSource( {
		name: SOURCE_NAME,
		usesContext: [ 'pattern/overrides' ],
		getValues: getPatternOverrideValues,
	} );
}
