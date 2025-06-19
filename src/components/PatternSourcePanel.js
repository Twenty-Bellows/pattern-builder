import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';

export const PatternSourcePanel = ({ patternPost }) => {

	if (!patternPost) {
		return null;
	}

	const [source, setSource] = useState(patternPost.source || 'user');

	useEffect(() => {
		setSource(patternPost.source || 'user');
	}, [patternPost.source]);

	const changeSourceStatus = (value) => {
		setSource(value);
		dispatch('core').editEntityRecord('postType', 'wp_block', patternPost.id, { source: value });
	};

	return (<>
		<div className="components-base-control">
			<label className="components-base-control__label">{'Where should this pattern be stored?'}</label>
			<ToggleGroupControl
				value={source}
				onChange={(value) => {
					changeSourceStatus(value);
				}}
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="theme" label="Theme" />
				<ToggleGroupControlOption value="user" label="User" />
			</ToggleGroupControl>
		</div>
		<br />
		{source === 'theme' && (
			<Text variant="muted">
				{__(
					'Theme Patterns are stored in files in your theme. They are tied to the current theme and can be exported with your theme to be used in other environments.',
					'pattern-builder'
				)}
			</Text>
		)}
		{source === 'user' && (
			<Text variant="muted">
				{__(
					'User Patterns are stored in the database and can be used across themes. They are not tied to a specific theme but are only available in this environment.',
					'pattern-builder'
				)}
			</Text>
		)}
	</>);
};
