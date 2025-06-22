import { __, _x } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useState, useEffect } from 'react';

export const PatternSyncedStatusPanel = ({ patternPost }) => {

	if (!patternPost) {
		return null;
	}

	const [synced, setSynced] = useState(patternPost.wp_pattern_sync_status === 'unsynced' ? 'false' : 'true');

	useEffect(() => {
		setSynced(patternPost.wp_pattern_sync_status === 'unsynced' ? 'false' : 'true');
	}, [patternPost.synced]);

	const changeSyncedStatus = (value) => {
		setSynced(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { wp_pattern_sync_status: value === 'true' ? '' : 'unsynced' });
	};

	return (<>
		<div className="components-base-control">
			<label className="components-base-control__label">{'Should this pattern be synced?'}</label>
			<ToggleGroupControl
				value={synced === 'true' ? 'true' : 'false'}
				onChange={(value) => {
					changeSyncedStatus(value);
				}}
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="true" label="Synced" />
				<ToggleGroupControlOption value="false" label="Unsynced" />
			</ToggleGroupControl>
		</div>
		<br />
		{synced === 'true' && (
			<Text variant="muted">
				{__(
					'Synced Patterns can be reused across your site and will be updated automatically when the original pattern is updated. Certain parts of the pattern (text and images) can be customized wherever they are used. This is useful for patterns that are used in multiple places and when you wish your design to be preserved and easily updated.',
					'pattern-builder'
				)}
			</Text>
		)}
		{synced === 'false' && (
			<Text variant="muted">
				{__(
					'Unsynced Patterns can be customized freely and will not update automatically when the original pattern is updated. This is useful for one-off designs or when you want to have full control over the pattern without worrying about updates.',
					'pattern-builder'
				)}
			</Text>
		)}
	</>);
};

