import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { _x } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

import { PatternSourcePanel } from './PatternSourcePanel';
import { PatternSyncedStatusPanel } from './PatternSyncedStatusPanel';
import { PatternAssociationsPanel } from './PatternAssociationsPanel';
import { PatternMetadataPanel } from './PatternMetadataPanel';
import { BlockBindingsPanel } from './BlockBindingsPanel';

/**
 * The post types whose editor gets the pattern panels: user patterns
 * (wp_block) and Pattern Builder's file-backed theme patterns (pb_pattern).
 */
const PATTERN_POST_TYPES = [ 'wp_block', 'pb_pattern' ];

export const PatternPanelAdditionsPlugin = () => {
	const { postType, post } = useSelect( ( select ) => {
		const _postType = select( 'core/editor' ).getCurrentPostType();
		const postId = select( 'core/editor' ).getCurrentPostId();
		const _post = PATTERN_POST_TYPES.includes( _postType )
			? select( 'core' ).getEntityRecord( 'postType', _postType, postId )
			: null;
		return { postType: _postType, post: _post };
	}, [] );

	if ( ! PATTERN_POST_TYPES.includes( postType ) ) {
		return null;
	}

	return <PatternBuilderPanel patternPost={ post } postType={ postType } />;
};

export const PatternBuilderPanel = ( { patternPost, postType } ) => {
	if ( ! patternPost ) {
		return null;
	}

	const isThemePattern = postType === 'pb_pattern';

	return (
		<>
			<PluginDocumentSettingPanel
				name={ 'pattern-panel-additions-source' }
				title={ _x( 'Pattern Source', 'UI String', 'pattern-builder' ) }
			>
				<PatternSourcePanel
					patternPost={ patternPost }
					postType={ postType }
				/>
			</PluginDocumentSettingPanel>

			<PluginDocumentSettingPanel
				name={ 'pattern-panel-additions-synced-status' }
				title={ _x(
					'Pattern Synced Status',
					'UI String',
					'pattern-builder'
				) }
			>
				<PatternSyncedStatusPanel
					patternPost={ patternPost }
					postType={ postType }
				/>
			</PluginDocumentSettingPanel>

			{ isThemePattern && (
				<PluginDocumentSettingPanel
					name={ 'pattern-panel-additions-metadata' }
					title={ _x(
						'Pattern Metadata',
						'UI String',
						'pattern-builder'
					) }
				>
					<PatternMetadataPanel patternPost={ patternPost } />
				</PluginDocumentSettingPanel>
			) }

			{ isThemePattern && (
				<PluginDocumentSettingPanel
					name={ 'pattern-panel-additions-restrictions' }
					title={ _x(
						'Pattern Associations',
						'UI String',
						'pattern-builder'
					) }
				>
					<PatternAssociationsPanel patternPost={ patternPost } />
				</PluginDocumentSettingPanel>
			) }

			<PluginDocumentSettingPanel
				name={ 'pattern-panel-additions-bindings' }
				title={ _x(
					'Pattern Bindings',
					'UI String',
					'pattern-builder'
				) }
			>
				<BlockBindingsPanel />
			</PluginDocumentSettingPanel>
		</>
	);
};
