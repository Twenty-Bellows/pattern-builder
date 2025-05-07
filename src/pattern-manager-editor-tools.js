/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Modal, Button } from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';
import { tool, code } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/block-serialization-default-parser';

import PatternManager from './pattern-manager';

const PatternManagerModal = ( { onRequestClose } ) => {
	return (
		<Modal
			title={ _x( 'Pattern Manager', 'UI String', 'pattern-manager' ) }
			className="pattern-manager_modal"
			onRequestClose={ onRequestClose }
			isFullScreen
		>
			<PatternManager />
		</Modal>
	);
};

export default function PatternManagerEditorTools() {

	const [ isPatternManagerOpen, setIsPatternManagerOpen ] = useState( false );
	const blocks = parse('<!-- wp:paragraph --><p>Hello from BlockPreview!</p><!-- /wp:paragraph -->');

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
				<BlockPreview blocks={blocks} viewportWidth={300} minHeight={300}/>
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
