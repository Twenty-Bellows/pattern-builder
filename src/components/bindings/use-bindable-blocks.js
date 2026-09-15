import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';

import {
	FALLBACK_SUPPORTED_ATTRIBUTES,
	collectBindableBlocks,
} from '../../utils/bindings';

/**
 * Every block in the document that WordPress will resolve bindings for.
 *
 * The table is core's, so a theme filtering
 * `block_bindings_supported_attributes` widens this panel with it.
 *
 * @return {Array<{block: Object, supported: string[]}>} The bindable blocks.
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
