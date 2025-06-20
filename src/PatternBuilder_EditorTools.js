/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { addFilter } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { EditorSidePanel } from './components/EditorSidePanel';
import { PatternPanelAdditionsPlugin } from './components/PatternPanelAdditions';
import { syncedPatternFilter } from './utils/syncedPatternFilter';

registerPlugin('pattern-builder-editor-side-panel', {
	render: EditorSidePanel,
});

registerPlugin( 'pattern-builder-pattern-panel-additions', {
	render: PatternPanelAdditionsPlugin,
} );

addFilter(
	'editor.BlockEdit',
	'pattern-builder/pattern-edit',
	syncedPatternFilter
);
