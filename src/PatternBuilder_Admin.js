import { createRoot } from '@wordpress/element';
import { registerCoreBlocks } from '@wordpress/block-library';

import PatternBuilder from './PatternBuilder';

export default function PatternBuilderAdmin() {

	// TODO: Is this the right place?  Maybe in the PatternEditor?
	registerCoreBlocks();
	// TODO: Register custom blocks from plugins?

	return (
		<PatternBuilder />
	);
}

window.addEventListener(
	'load',
	function () {
		const domNode = document.getElementById( 'pattern-builder-app' );
		const root = createRoot( domNode );
		root.render( <PatternBuilderAdmin /> );
	},
	false
);
