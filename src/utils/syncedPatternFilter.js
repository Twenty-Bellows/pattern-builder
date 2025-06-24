import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';

import { SyncedPatternRenderer } from '../components/SyncedPatternRenderer';

/**
 *
 * SyncedPatternFilter
 *
 * This filter checks if the block being edited is a core/pattern block with a slug and content.
 * If so, it renders the SyncedPatternRenderer component instead of the default BlockEdit.
 *
 */
export const syncedPatternFilter = (BlockEdit) => (props) => {
	const { name, attributes } = props;

	if (name === 'core/pattern' && attributes.slug && attributes.content) {
		const selectedPattern = useSelect(
			(select) =>
				select(blockEditorStore).__experimentalGetParsedPattern(
					attributes.slug
				),
			[props.attributes.slug]
		);
		if (selectedPattern?.blocks?.length === 1 && selectedPattern.blocks[0].name === 'core/block') {
			return <SyncedPatternRenderer {
				...props
			} />;
		}
	}
	return <BlockEdit {...props} />;
};


