import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

import {
	FALLBACK_SUPPORTED_ATTRIBUTES,
	collectBindableBlocks,
} from '../../utils/bindings';

/**
 * Every block in the document that WordPress will resolve bindings for.
 *
 * Which blocks those are is core's decision, not this plugin's: WordPress 6.9
 * added `get_block_bindings_supported_attributes()` and hands the whole table
 * to the editor as a setting, which is also where the two
 * `block_bindings_supported_attributes` filters land — so a theme or plugin
 * that widens the table widens this panel with it. On 6.8 the table is private
 * to `WP_Block` and the constant stands in for it.
 *
 * @return {Array<{block: Object, supported: string[]}>} The bindable blocks,
 *         in document order, each with the attributes it can bind.
 */
export function useBindableBlocks() {
	return useSelect( ( select ) => {
		const { getBlocks, getSettings } = select( blockEditorStore );

		return collectBindableBlocks(
			getBlocks(),
			getSettings().__experimentalBlockBindingsSupportedAttributes ||
				FALLBACK_SUPPORTED_ATTRIBUTES
		);
	}, [] );
}
