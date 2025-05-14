// @WordPress dependencies
import {
	BlockCanvas,
	BlockEditorProvider,
	BlockInspector,
	__experimentalListView as ListView,
	__experimentalLibrary as InserterLibrary
} from '@wordpress/block-editor';
import { Panel, TabPanel, Button } from '@wordpress/components';
import { EditorHistoryRedo, EditorHistoryUndo } from '@wordpress/editor';
import { useState, useEffect } from '@wordpress/element';
import { parse, serialize } from '@wordpress/blocks';
import { chevronLeft } from '@wordpress/icons';
import { ToolbarButton, ToolbarItem } from '@wordpress/components';

import { getEditorSettings, savePattern, deletePattern } from '../resolvers';
import { PatternDetails } from '../components/PatternDetails';
import PatternSearch from '../components/PatternSearch';


export const PatternEditor = ({ pattern, onClose }) => {

	const [updatedPattern, setUpdatedPattern] = useState(pattern);
	const [blocks, setBlocks] = useState(parse(pattern.content));
	const [editorSettings, setEditorSettings] = useState(null);

	// TODO: Optimize me
	useEffect(() => {
		getEditorSettings()
			.then((data) => {
				setEditorSettings(data);
			});
	}, []);

	const handleSavePattern = () => {
		savePattern({
			...updatedPattern,
			content: serialize(blocks),
		})
		.then((response) => {
			alert('Pattern saved successfully');
		})
		.catch((error) => {
			alert('Error saving pattern');
		});
	}

	const handleDeletePattern = (pattern) => {
		if (confirm('Are you sure you want to delete this pattern?')) {
			deletePattern(pattern)
				.then((response) => {
					if (onClose) {
						onClose();
					}
				})
				.catch((error) => {
					console.error('Error deleting pattern:', error);
				});
		}
	}

	if (!editorSettings) {
		return (
			<div className="pattern-manager__editor">
				Loading...
			</div>
		);
	}

	return (
		<div className="pattern-manager__editor">
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
					<div style={{ flexGrow: 1 }} />
					<Button
						variant="primary"
						onClick={ handleSavePattern }
					>Save</Button>
				</div>

				<div className="pattern-editor__body">
					<div className="pattern-editor__list-view">
						<ListView isExpanded />
					</div>
					<div className="pattern-editor__content">
						<BlockCanvas height="100%" styles={editorSettings.styles}/>
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
								]}
							>
								{(tab) => (
									<>
										{tab.name === 'pattern' && (
											<Panel>
												<PatternDetails pattern={ updatedPattern } onChange={ setUpdatedPattern } onDeletePattern={ handleDeletePattern } />
											</Panel>
										)}
										{tab.name === 'block' && (
											<Panel>
												<BlockInspector />
											</Panel>
										)}
										{tab.name === 'blocks' && (
											<Panel className="pattern-manager__editor__add-blocks-panel">
												<InserterLibrary />
											</Panel>
										)}
										{tab.name === 'patterns' && (
											<Panel>
												<PatternSearch />
											</Panel>
										)}
									</>
								)}
							</TabPanel>
						</div>
					</div>
				</div>
			</BlockEditorProvider>
		</div>
	);
};

export default PatternEditor;
