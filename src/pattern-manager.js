import { __, _x } from '@wordpress/i18n';

const PatternManager = () => {
	return (
		<div className="pattern-manager-modal">
			<h2>{_x('Hello Pattern Manager', 'UI String', 'pattern-manager')}</h2>
			<p>{_x('This is the Pattern Manager modal.', 'UI String', 'pattern-manager')}</p>
		</div>
	);
}

export default PatternManager;
