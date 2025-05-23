// @WordPress dependencies
import { __ } from '@wordpress/i18n';
import {
	BlockCanvas,
	BlockEditorProvider,
	BlockInspector,
	__experimentalListView as ListView,
	__experimentalLibrary as InserterLibrary
} from '@wordpress/block-editor';
import { Panel, TabPanel, Button, TextareaControl } from '@wordpress/components';
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { EditorHistoryRedo, EditorHistoryUndo } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { parse, serialize } from '@wordpress/blocks';
import { chevronLeft } from '@wordpress/icons';
import { ToolbarItem } from '@wordpress/components';
import { useSelect, dispatch, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { PatternDetails } from './PatternDetails';
import PatternSearch from './PatternSearch';
import { formatBlockMarkup, validateBlockMarkup } from '../utils/formatters';
import BlockBindingsPanel from './BlockBindingsPanel';


export const PatternEditor = ({ pattern, onClose }) => {

	const [updatedPattern, setUpdatedPattern] = useState(pattern);
	const [blocks, setBlocks] = useState(parse(pattern.content));

	const [codeMarkupIsValid, setCodeMarkupIsValid] = useState(true);
	const [codeMarkup, setCodeMarkup] = useState('');
	const [editorMode, setEditorMode] = useState('visual');

	const editorSettings = useSelect((select) => select('pattern-builder').getEditorConfiguration(), []);
	const { createSuccessNotice, createWarningNotice } = useDispatch(noticesStore);

	const onEditorModeChange = (newMode) => {

		if (newMode === 'markup') {
			setCodeMarkup(formatBlockMarkup(serialize(blocks)));
		}
		else if (newMode === 'visual') {
			setBlocks(parse(codeMarkup));
		}
		else if (newMode === 'code') {
			// Handle code mode
			// This is where you would implement the logic for the code editor
			// For now, we will just set the blocks to the current state
			setBlocks(parse(codeMarkup));
		}
		else if (newMode === 'style') {
			// Handle style mode
			// This is where you would implement the logic for the style editor
			// For now, we will just set the blocks to the current state
			setBlocks(parse(codeMarkup));
		}

		setEditorMode(newMode);
	}

	const handleSavePattern = () => {

		dispatch('pattern-builder').saveActivePattern({
			...updatedPattern,
			content: serialize(blocks),
		})
			.then(() => {
				createSuccessNotice(__('Pattern was saved sucessfully'), {
					isDismissible: true,
				})
			})
			.catch((err) => {
				createWarningNotice(__('Error saving pattern'), {
					isDismissible: true,
				});
			});
	}

	if (!editorSettings) {
		return (
			<div className="pattern-builder__editor">
				Loading...
			</div>
		);
	}

	return (
		<div className="pattern-builder__editor">
			<BlockEditorProvider
				value={blocks}
				onInput={setBlocks}
				onChange={setBlocks}
				settings={editorSettings}
			>
				<div className="pattern-editor__header">
					<Button
						onClick={onClose}
						icon={chevronLeft}
						label="Back"
					/>
					<ToolbarItem
						as={EditorHistoryUndo}
						variant={'tertiary'}
						size="compact"
					/>
					<ToolbarItem
						as={EditorHistoryRedo}
						variant={'tertiary'}
						size="compact"
					/>
					<ToggleGroupControl
						value={editorMode}
						onChange={onEditorModeChange}
						__nextHasNoMarginBottom
					>
						<ToggleGroupControlOption value="visual" label="Visual" disabled={!codeMarkupIsValid}/>
						<ToggleGroupControlOption value="markup" label="Markup" disabled={!codeMarkupIsValid} />
						<ToggleGroupControlOption value="code" label="Code" disabled={!codeMarkupIsValid} />
						<ToggleGroupControlOption value="style" label="Style" disabled={!codeMarkupIsValid} />
					</ToggleGroupControl>

					<div style={{ flexGrow: 1 }} />
					<Button
						variant="primary"
						onClick={handleSavePattern}
					>Save</Button>
				</div>

				{/* Render based on editorMode */}
				{editorMode === 'visual' && (
					<div className="pattern-editor__body">
						<div className="pattern-editor__list-view">
							<ListView isExpanded />
						</div>
						<div className="pattern-editor__content">
							<BlockCanvas height="100%" styles={editorSettings.styles} />
						</div>
						<div className="pattern-editor__sidebar">
							<div className="pattern-sidebar">
								<TabPanel
									className="pattern-tabs"
									activeClass="is-active"
									tabs={[
										{
											name: 'pattern',
											title: 'Pattern',
										},
										{
											name: 'block',
											title: 'Block',
										},
										{
											name: 'blocks',
											title: '+Blocks',
										},
										{
											name: 'patterns',
											title: '+Patterns',
										},
										{
											name: 'bindings',
											title: 'Bindings',
										}
									]}
								>
									{(tab) => (
										<>
											{tab.name === 'pattern' && (
												<Panel>
													<PatternDetails pattern={updatedPattern} onChange={setUpdatedPattern} />
												</Panel>
											)}
											{tab.name === 'block' && (
												<Panel>
													<BlockInspector />
												</Panel>
											)}
											{tab.name === 'blocks' && (
												<Panel className="pattern-builder__editor__add-blocks-panel">
													<InserterLibrary />
												</Panel>
											)}
											{tab.name === 'patterns' && (
												<Panel>
													<PatternSearch />
												</Panel>
											)}
											{tab.name === 'bindings' && (
												<Panel>
													<BlockBindingsPanel />
												</Panel>
											)}
										</>
									)}
								</TabPanel>
							</div>
						</div>
					</div>
				)}

				{editorMode === 'markup' && (
					<TextareaControl
						className="pattern-editor__code-editor"
						value={codeMarkup}
						onChange={(value) => {
							// Validate the block markup
							setCodeMarkupIsValid(validateBlockMarkup(value));
							setCodeMarkup(value);
						}}
						placeholder="Enter your markup here..."
					/>
				)}

				{editorMode === 'code' && (
					<div className="pattern-editor__code-placeholder">
						{/* Placeholder for Code mode */}
						<p>Code editor placeholder</p>
					</div>
				)}

				{editorMode === 'style' && (
					<div className="pattern-editor__style-placeholder">
						{/* Placeholder for Style mode */}
						<p>Style editor placeholder</p>
					</div>
				)}
			</BlockEditorProvider>
		</div>
	);
};

export default PatternEditor;
