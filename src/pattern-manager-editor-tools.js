/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Modal, Button } from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';
import { tool, widget } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { parse } from '@wordpress/block-serialization-default-parser';

import PatternManager from './pattern-manager';
import PatternSearch from './components/PatternSearch';

const PatternManagerModal = ( { onRequestClose } ) => {
	return (
		<Modal
			title={ _x( 'Pattern Manager', 'UI String', 'pattern-manager' ) }
			className="pattern-manager__modal"
			onRequestClose={ onRequestClose }
			isFullScreen
		>
			<PatternManager />
		</Modal>
	);
};

export default function PatternManagerEditorTools() {

	const [ isPatternManagerOpen, setIsPatternManagerOpen ] = useState( false );

	return (
		<>
			<PluginSidebarMoreMenuItem
				target="pattern-manager-sidebar"
				icon={ tool }
			>
				{ _x( 'Pattern Manager', 'UI String', 'pattern-manager' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				className='pattern-manager__editor-sidebar'
				name="pattern-manager-sidebar"
				icon={ widget }
				title={ _x(
					'Patterns',
					'UI String',
					'pattern-manager'
				) }
			>

				<Button
					variant='primary'
					icon={widget}
					onClick={() =>
						setIsPatternManagerOpen(true)
					}
					style={{width: '100%'}}
				>
					{__(
						'Manage Patterns',
						'pattern-manager'
					)}
				</Button>
				<PatternSearch />
			</PluginSidebar>

			{ isPatternManagerOpen && (
				<PatternManagerModal
					onRequestClose={ () => setIsPatternManagerOpen( false ) }
				/>
			) }

		</>
	);
}

registerPlugin( 'pattern-manager', {
	render: PatternManagerEditorTools,
} );
