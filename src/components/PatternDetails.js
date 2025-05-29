import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { TextControl, TextareaControl, SelectControl, ToggleControl, Button, FormTokenField, Panel } from '@wordpress/components';
import { PanelBody } from '@wordpress/components';
import { dispatch, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';


export const PatternDetails = ({ pattern, onChange }) => {

	const [editablePattern, setEditablePattern] = useState({ ...pattern });
	const { createWarningNotice } = useDispatch(noticesStore);


	// Use useEffect to call onChange when editablePattern updates
	useEffect(() => {
		if (onChange) {
			onChange(editablePattern);
		}
	}, [editablePattern, onChange]);

	const handleConvertToThemePattern = () => {
		setEditablePattern((prev) => ({ ...prev, ['source']: 'theme' }));
	}

	const handleConvertToUserPattern = () => {
		setEditablePattern((prev) => ({ ...prev, ['source']: 'user' }));
	}

	const handleDeletePattern = () => {
		if (confirm('Are you sure you want to delete this pattern?')) {
			dispatch('pattern-builder').deleteActivePattern( pattern )
				.catch((err) => {
					createWarningNotice(err.message, {
						isDismissible: true,
					});
				});
		}
	}

	const handleChange = (field, value) => {
		setEditablePattern((prev) => ({ ...prev, [field]: value }));
	};

	return (
		<Panel>
			<PanelBody>
			<TextControl
				label="Title"
				value={editablePattern.title || ''}
				onChange={(value) => handleChange('title', value)} __next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label="Description"
				value={editablePattern.description || ''}
				onChange={(value) => handleChange('description', value)}
				__nextHasNoMarginBottom
			/>

			<div className="components-base-control">
				<label className="components-base-control__label">Synced</label>
				<ToggleControl
					checked={editablePattern.synced}
					onChange={(value) => handleChange('synced', value)}
					__nextHasNoMarginBottom
				/>
			</div>
			{editablePattern.source === 'theme' && (
				<div className="components-base-control">
					<label className="components-base-control__label">Hidden from User</label>
					<ToggleControl
						checked={!editablePattern.inserter}
						onChange={(value) => handleChange('inserter', !value)}
						__nextHasNoMarginBottom
					/>
				</div>
			)}

		</PanelBody>

		<PanelBody title={__('Organization', 'pattern-builder')} initialOpen={false} >
			<FormTokenField
				label="Categories"
				value={editablePattern.categories}
				onChange={(value) => handleChange('categories', value)}
			/>
			{editablePattern.source === 'theme' && (
				<FormTokenField
					label="Keywords"
					value={editablePattern.keywords}
					tokenizeOnBlur
					onChange={(value) => handleChange('keywords', value)}
				/>
			)}
		</PanelBody>
		{editablePattern.source === 'theme' && (
				<PanelBody title={__('Restrictions', 'pattern-builder')} initialOpen={false} >

					<p>{__('Restrict this pattern to only be used in these contexts. This is only available for Theme Patterns.', 'pattern-builder')}</p>

					<FormTokenField
						__experimentalShowHowTo={false}
						label="Block Types"
						value={editablePattern.blockTypes}
						tokenizeOnBlur
						onChange={(value) => handleChange('blockTypes', value)}
					/>
					<FormTokenField
						__experimentalShowHowTo={false}
						label="Template Types"
						value={editablePattern.templateTypes}
						tokenizeOnBlur
						onChange={(value) => handleChange('templateTypes', value)}
					/>
					<FormTokenField
						__experimentalShowHowTo={false}
						label="Post Types"
						value={editablePattern.postTypes}
						tokenizeOnBlur
						onChange={(value) => handleChange('postTypes', value)}
					/>
				</PanelBody>


			)}

			<PanelBody title={__('Advanced', 'pattern-builder')} initialOpen={false} >

					<p className="pattern-builder__advanced-details-warning">{__('The fields below are dangerous to change. Proceed with caution and understand the consequences.', 'pattern-builder')}</p>

					<TextControl
						label="Slug"
						value={editablePattern.name || ''}
						onChange={(value) => handleChange('name', value)}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					{editablePattern.source === 'theme' && (
						<Button
							onClick={handleConvertToUserPattern}
							variant="primary"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							{__('Convert to User Pattern', 'pattern-builder')}
						</Button>
					)}
					{editablePattern.source === 'user' && (
						<Button
							onClick={handleConvertToThemePattern}
							variant="primary"
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						>
							{__('Convert to Theme Pattern', 'pattern-builder')}
						</Button>
					)}
					<Button
						isDestructive
						variant="primary"
						label="Delete Pattern"
						onClick={handleDeletePattern}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					>
						Delete Pattern
					</Button>

			</PanelBody>
		</Panel>
	);
};

export default PatternDetails;
