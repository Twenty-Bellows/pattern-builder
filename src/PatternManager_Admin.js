import { createRoot } from '@wordpress/element';
import PatternManager from './PatternManager';
import { registerCoreBlocks } from '@wordpress/block-library';

export default function PatternManagerAdmin() {

	// TODO: Is this the right place?  Maybe in the PatternEditor?
	registerCoreBlocks();
	// TODO: Register custom blocks from plugins?

	return (
		<PatternManager />
	);
}

window.addEventListener(
	'load',
	function () {
		const domNode = document.getElementById( 'pattern-manager-app' );
		const root = createRoot( domNode );
		root.render( <PatternManagerAdmin /> );
	},
	false
);
