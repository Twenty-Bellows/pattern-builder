/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { EditorSidePanel } from './components/EditorSidePanel';
import { PatternPanelAdditionsPlugin } from './components/PatternPanelAdditions';
import { PatternSaveMonitor } from './utils/patternSaveMonitor';
import { registerEditPatternToolbarButton } from './components/EditPatternToolbarButton';

registerEditPatternToolbarButton();

registerPlugin( 'pattern-builder-editor-side-panel', {
	render: EditorSidePanel,
} );

registerPlugin( 'pattern-builder-pattern-panel-additions', {
	render: PatternPanelAdditionsPlugin,
} );

registerPlugin( 'pattern-builder-save-monitor', {
	render: PatternSaveMonitor,
} );
