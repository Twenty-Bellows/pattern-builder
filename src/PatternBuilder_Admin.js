import { createRoot } from '@wordpress/element';
import { AdminLandingPage } from './components/AdminLandingPage';

window.addEventListener(
	'load',
	function () {
		const domNode = document.getElementById( 'pattern-builder-app' );
		const root = createRoot( domNode );
		root.render( <AdminLandingPage /> );
	},
	false
);
