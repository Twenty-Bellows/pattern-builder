import { createRoot } from '@wordpress/element';
import PatternManager from './pattern-manager';


export default function PatternManagerAdmin() {
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
