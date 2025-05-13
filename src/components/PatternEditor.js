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

import { getEditorSettings, savePattern } from '../resolvers';
import { PatternDetails } from '../components/PatternDetails';


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
						<ListView />
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
										name: 'add',
										title: 'Add',
									},
									{
										name: 'bindings',
										title: 'Bindings',
									},
									{
										name: 'history',
										title: 'History',
									},
								]}
							>
								{(tab) => (
									<>
										{tab.name === 'pattern' && (
											<Panel>
												<PatternDetails pattern={ updatedPattern } onChange={ setUpdatedPattern } />
											</Panel>
										)}
										{tab.name === 'block' && (
											<Panel>
												<BlockInspector />
											</Panel>
										)}
										{tab.name === 'add' && (
											<Panel>
												<InserterLibrary />
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
