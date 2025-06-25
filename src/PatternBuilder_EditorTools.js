/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { EditorSidePanel } from './components/EditorSidePanel';
import { PatternPanelAdditionsPlugin } from './components/PatternPanelAdditions';
import './utils/syncedPatternFilter';

registerPlugin( 'pattern-builder-editor-side-panel', {
	render: EditorSidePanel,
} );

registerPlugin( 'pattern-builder-pattern-panel-additions', {
	render: PatternPanelAdditionsPlugin,
} );
