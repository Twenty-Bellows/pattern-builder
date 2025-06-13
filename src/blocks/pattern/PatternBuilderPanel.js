import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import PatternDetails from '../../components/PatternDetails';

export const PatternBuilderPanelPlugin = () => {

	const { postType, post } = useSelect(
		( select ) => {
			const postType = select( 'core/editor' ).getCurrentPostType();
			const postId = select( 'core/editor' ).getCurrentPostId();
			const post = select( 'core' ).getEntityRecord( 'postType', postType, postId );
			return { postType, post };
		},
		[]
	);

	if ( postType !== 'wp_block') {
		return null;
	}

	return <PatternBuilderPanel patternPost={ post } />;
}

export const PatternBuilderPanel = ({ patternPost }) => {

	console.log('PatternBuilderPanel', patternPost);

	return (<PluginDocumentSettingPanel
		name={'pattern-edit-panel'}
		title={'Pattern Builder'}
	>
		<PatternDetails />
	</PluginDocumentSettingPanel>);
};
