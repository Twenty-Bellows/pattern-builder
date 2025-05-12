import { useState } from '@wordpress/element'
import { TextControl, TextareaControl, SelectControl, ToggleControl } from '@wordpress/components';
import { Button } from '@wordpress/components';

export const PatternDetails = ({ pattern, onChange }) => {

	const [editablePattern, setEditablePattern] = useState({ ...pattern });

	const handleChange = (field, value) => {
		setEditablePattern((prev) => ({ ...prev, [field]: value }));
		if(onChange) {
			onChange( editablePattern );
		}
	};

	return (
		<div className="pattern-manager__pattern-details">
			<TextControl
				label="Title"
				value={editablePattern.title || ''}
				onChange={(value) => handleChange('title', value)}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label="Description"
				value={editablePattern.description || ''}
				onChange={(value) => handleChange('description', value)}
				__nextHasNoMarginBottom
			/>
			<div className="components-base-control">
				<label className="components-base-control__label">Synced Pattern</label>
				<ToggleControl
					checked={editablePattern.synced || false}
					onChange={(value) => handleChange('synced', value)}
					__nextHasNoMarginBottom
				/>
			</div>

			<details>
				<summary>Advanced</summary>

			<h3>Warning</h3>
			<p>The fields below are dangerous to change. Proceed with caution.</p>

			<TextControl
				label="Slug"
				value={editablePattern.name || ''}
				onChange={(value) => handleChange('name', value)}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label="Source"
				value={editablePattern.source || ''}
				options={[
					{ label: 'Core', value: 'core', disabled: true },
					{ label: 'Theme', value: 'theme' },
					{ label: 'User', value: 'user' },
				]}
				onChange={(value) => handleChange('source', value)}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<Button
				isDestructive
				variant='secondary'
				label='Delete Pattern'
				onClick={() => {
					console.log('Delete pattern:', editablePattern);
				}}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			>
				Delete Pattern
			</Button>
			</details>

		</div>
	);
};

export default PatternDetails;
