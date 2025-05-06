/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarItem, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Modal, Button } from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';
import { tool, code } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import PatternManager from './pattern-manager';

const PatternManagerModal = ( { onRequestClose } ) => {
	return (
		<Modal
			title={ _x( 'Pattern Manager', 'UI String', 'pattern-manager' ) }
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
				name="pattern-manager-sidebar"
				icon={ tool }
				title={ _x(
					'Pattern Manager',
					'UI String',
					'pattern-manager'
				) }
			>
				<Button
					icon={code}
					onClick={() =>
						setIsPatternManagerOpen(true)
					}
				>
					{__(
						'View Custom Styles',
						'create-block-theme'
					)}
				</Button>
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
