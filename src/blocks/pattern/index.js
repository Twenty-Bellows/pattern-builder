import { addFilter } from '@wordpress/hooks';
import { registerPlugin } from '@wordpress/plugins';

import { SyncedPatternFilter } from './SyncedPatternRenderer';
import { PatternBuilderPanelPlugin } from './PatternBuilderPanel';

addFilter(
	'editor.BlockEdit',
	'pattern-builder/pattern-edit',
	SyncedPatternFilter
);

registerPlugin( 'meta-edit', {
	icon: 'palmtree',
	title: 'Pattern Builder',
	render: PatternBuilderPanelPlugin,
} );
