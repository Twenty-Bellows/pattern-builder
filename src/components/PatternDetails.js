import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { TextControl, TextareaControl, SelectControl, ToggleControl, Button, FormTokenField } from '@wordpress/components';

export const PatternDetails = ({ pattern, onChange, onDeletePattern }) => {

    const [editablePattern, setEditablePattern] = useState({ ...pattern });

    // Use useEffect to call onChange when editablePattern updates
    useEffect(() => {
        if (onChange) {
            onChange(editablePattern);
        }
    }, [editablePattern, onChange]);

	const handleCategoryChange = (categories) => {
		const updatedCategories = categories.map((category) => {
			if (typeof category === 'string') {
				return {
					id: null,
					name: category,
					value: category,
					slug: null,
				};
			}
			return {
				id: category.id,
				name: category.name,
				value: category.name,
				slug: category.slug,
			};
		});
		setEditablePattern((prev) => ({ ...prev, categories: updatedCategories }));
	};

    const handleChange = (field, value) => {
        setEditablePattern((prev) => ({ ...prev, [field]: value }));
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
			<FormTokenField
				label="Categories"
				value={
					editablePattern.categories.map((category) => {
						if(typeof category === 'string') {
							return {
								value: category,
								name: category,
							}
						}
						return {
							id: category.id,
							value: category.name,
							name: category.name,
							slug: category.slug,
						};
					}) || []}
				onChange={handleCategoryChange}
			/>
			{editablePattern.source === 'theme' && (
				<FormTokenField
					label="Keywords"
					value={editablePattern.keywords}
					onChange={(value) => handleChange('keywords', value)}
				/>
			)}
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
   	                 checked={ ! editablePattern.inserter}
   	                 onChange={(value) => handleChange('inserter', ! value)}
   	                 __nextHasNoMarginBottom
   	             />
   	         </div>
			)}
            <details>

                <summary>Advanced</summary>

				<div className="pattern-manager__advanced-details">

				<p className="pattern-manager__advanced-details-warning">{__('The fields below are dangerous to change. Proceed with caution and understand the consequences.', 'pattern-manager')}</p>

                <TextControl
                    label="Slug"
                    value={editablePattern.name || ''}
                    onChange={(value) => handleChange('name', value)}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                />
				{editablePattern.source === 'theme' && (
					<Button
						onClick="handleConvertToUserPattern"
						variant="primary"
                    	__next40pxDefaultSize
                    	__nextHasNoMarginBottom
					>
						{__('Convert to User Pattern', 'pattern-manager')}
					</Button>
				)}
				{editablePattern.source === 'user' && (
					<Button
						onClick="handleConvertToThemePattern"
						variant="primary"
                    	__next40pxDefaultSize
                    	__nextHasNoMarginBottom
					>
						{__('Convert to Theme Pattern', 'pattern-manager')}
					</Button>
				)}
                <Button
                    isDestructive
                    variant="primary"
                    label="Delete Pattern"
                    onClick={()=>{onDeletePattern(pattern);}}
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                >
                    Delete Pattern
                </Button>
				</div>
            </details>
        </div>
    );
};

export default PatternDetails;
