/**
 * Lets a pattern block host content the way a synced pattern does.
 */

import { store as blockEditorStore } from '@wordpress/block-editor';
import { getBlockBindingsSource } from '@wordpress/blocks';
import { subscribe } from '@wordpress/data';

import { getOverridesUpdate } from './get-overrides-update';

const SOURCE_NAME = 'core/pattern-overrides';
const HOST_BLOCKS = [ 'core/block', 'core/pattern' ];

/**
 * Teaches the registered source to write to a pattern host.
 *
 * @param {Object} source The registered binding source.
 * @return {boolean} Whether the source was amended.
 */
function extendSource( source ) {
	const originalSetValues = source.setValues;

	if ( typeof originalSetValues !== 'function' ) {
		return false;
	}

	const setValues = ( args ) => {
		const { select, dispatch, clientId, bindings } = args;
		const { getBlockAttributes, getBlockName, getBlockParentsByBlockName } =
			select( blockEditorStore );

		const [ hostClientId ] = getBlockParentsByBlockName(
			clientId,
			HOST_BLOCKS,
			true
		);

		const content = getOverridesUpdate( {
			name: getBlockAttributes( clientId )?.metadata?.name,
			hostBlockName: hostClientId
				? getBlockName( hostClientId )
				: undefined,
			bindings,
			content: getBlockAttributes( hostClientId )?.content,
		} );

		if ( null === content ) {
			return originalSetValues( args );
		}

		dispatch( blockEditorStore ).updateBlockAttributes( hostClientId, {
			content,
		} );
	};

	try {
		source.setValues = setValues;
	} catch ( error ) {
		return false;
	}
	return source.setValues === setValues;
}

/**
 * Amends the binding source once core has registered it.
 *
 * @return {void}
 */
export function extendPatternOverridesSource() {
	let done = false;

	const attempt = () => {
		if ( done ) {
			return true;
		}
		const source = getBlockBindingsSource( SOURCE_NAME );

		if ( typeof source?.setValues !== 'function' ) {
			return false;
		}

		done = extendSource( source );

		return done;
	};

	if ( attempt() ) {
		return;
	}

	const unsubscribe = subscribe( () => {
		if ( attempt() ) {
			unsubscribe();
		}
	} );
}
