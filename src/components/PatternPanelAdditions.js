import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';

export const PatternPanelAdditionsPlugin = () => {

	const { postType, post } = useSelect(
		( select ) => {
			const postType = select( 'core/editor' ).getCurrentPostType();
			const postId = select( 'core/editor' ).getCurrentPostId();
			const post = select( 'core' ).getEntityRecord( 'postType', postType, postId );
			return { postType, post };
		}
	);

	if ( postType !== 'wp_block') {
		return null;
	}

	return <PatternBuilderPanel patternPost={ post } />;
}

export const PatternBuilderPanel = ({ patternPost }) => {

	if ( ! patternPost ) {
		return null;
	}

	console.log('PatternBuilderPanel', patternPost);

	const changeSyncedStatus = ( value ) => {
		dispatch( 'core' ).editEntityRecord( 'postType', 'wp_block', patternPost.id, { wp_pattern_sync_status: value ? '' : 'unsynced' } );
	}

	const changeSourceStatus = ( value ) => {
		dispatch( 'core' ).editEntityRecord( 'postType', 'wp_block', patternPost.id, { source: value } );
	}

	return (<PluginDocumentSettingPanel
		name={'pattern-edit-panel'}
		title={'Pattern Builder'}
	>

		<div className="components-base-control">
			<label className="components-base-control__label">{'Pattern Source'}</label>
			<ToggleGroupControl
				value={patternPost.source || 'user'}
				onChange={(value) => {
					changeSourceStatus( value );
				}}
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="theme" label="Theme" />
				<ToggleGroupControlOption value="user" label="User" />
			</ToggleGroupControl>
		</div>

		<div className="components-base-control">
			<label className="components-base-control__label">{'Synced Status'}</label>
			<ToggleGroupControl
				value={patternPost.wp_pattern_sync_status === 'unsynced' ? 'false' : 'true'}
				onChange={(value) => {
					changeSyncedStatus(value === 'true');
				}}
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="true" label="Synced" />
				<ToggleGroupControlOption value="false" label="Not synced" />
			</ToggleGroupControl>
		</div>
	</PluginDocumentSettingPanel>);
};
