import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { PatternSourcePanel } from './PatternSourcePanel';
import { PatternSyncedStatusPanel } from './PatternSyncedStatusPanel';

export const PatternPanelAdditionsPlugin = () => {

	const { postType, post } = useSelect(
		(select) => {
			const postType = select('core/editor').getCurrentPostType();
			const postId = select('core/editor').getCurrentPostId();
			const post = select('core').getEntityRecord('postType', postType, postId);
			return { postType, post };
		}
	);

	if (postType !== 'wp_block') {
		return null;
	}

	return <PatternBuilderPanel patternPost={post} />;
}

export const PatternBuilderPanel = ({ patternPost }) => {

	if (!patternPost) {
		return null;
	}

	return (<>
		<PluginDocumentSettingPanel
			name={'pattern-panel-additions-source'}
			title={'Pattern Source'}
		>
			<PatternSourcePanel patternPost={patternPost} />
		</PluginDocumentSettingPanel>

		<PluginDocumentSettingPanel
			name={'pattern-panel-additions-synced-status'}
			title={'Pattern Synced Status'}
		>
			<PatternSyncedStatusPanel patternPost={patternPost} />
		</PluginDocumentSettingPanel>

		{/* <PluginDocumentSettingPanel
			name={'pattern-panel-additions-bindings'}
			title={'Pattern Bindings'}
		>
			<BlockBindingsPanel />
		</PluginDocumentSettingPanel> */}
	</>);
};
