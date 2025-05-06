/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { _x } from '@wordpress/i18n';
import { tool } from '@wordpress/icons';

export default function PatternManager() {
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
				<h2>Hello Pattern Manager</h2>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'pattern-manager', {
	render: PatternManager,
} );
