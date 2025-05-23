/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Modal, Button } from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';
import { tool, widget } from '@wordpress/icons';
import { useState } from '@wordpress/element';

import PatternBuilder from './PatternBuilder';
import PatternSearch from './components/PatternSearch';

const PatternBuilderModal = ( { onRequestClose } ) => {
	return (
		<Modal
			title={ _x( 'Pattern Builder', 'UI String', 'pattern-builder' ) }
			className="pattern-builder__modal"
			onRequestClose={ onRequestClose }
			isFullScreen
		>
			<PatternBuilder />
		</Modal>
	);
};

export default function PatternBuilderEditorTools() {

	const [ isPatternBuilderOpen, setIsPatternBuilderOpen ] = useState( false );

	return (
		<>
			<PluginSidebarMoreMenuItem
				target="pattern-builder-sidebar"
				icon={ tool }
			>
				{ _x( 'Pattern Builder', 'UI String', 'pattern-builder' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				className='pattern-builder__editor-sidebar'
				name="pattern-builder-sidebar"
				icon={ widget }
				title={ _x(
					'Patterns',
					'UI String',
					'pattern-builder'
				) }
			>

				<Button
					variant='primary'
					icon={widget}
					onClick={() =>
						setIsPatternBuilderOpen(true)
					}
					style={{width: '100%'}}
				>
					{__(
						'Manage Patterns',
						'pattern-builder'
					)}
				</Button>
				<PatternSearch />
			</PluginSidebar>

			{ isPatternBuilderOpen && (
				<PatternBuilderModal
					onRequestClose={ () => setIsPatternBuilderOpen( false ) }
				/>
			) }

		</>
	);
}

registerPlugin( 'pattern-builder', {
	render: PatternBuilderEditorTools,
} );
