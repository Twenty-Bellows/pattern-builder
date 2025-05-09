import {
	BlockCanvas,
	BlockEditorProvider,
	BlockInspector,
	__experimentalListView as ListView,
	__experimentalLibrary as InserterLibrary
} from '@wordpress/block-editor';
import { Panel, TabPanel, Button } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { parse } from '@wordpress/blocks';
import { chevronLeft } from '@wordpress/icons';
import { getEditorSettings } from '../resolvers';

const EditorSidebar = () => {

	return (
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
				]}
			>
				{(tab) => (
					<>
						{tab.name === 'pattern' && (
							<Panel>
								<p>Pattern Details</p>
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
	);
};

export const PatternEditor = ({ pattern, onClose }) => {

	const [blocks, setBlocks] = useState(parse(pattern.content));
	const [editorSettings, setEditorSettings] = useState(null);

	// TODO: Optimize me
	useEffect(() => {
		getEditorSettings()
			.then((data) => {
				setEditorSettings(data);
			});
	}, []);

	if (!editorSettings) {
		return (
			<div className="pattern-manager_editor">
				Loading...
			</div>
		);
	}

	return (
		<div className="pattern-manager_editor">
			<BlockEditorProvider
				value={blocks}
				onInput={setBlocks}
				onChange={setBlocks}
				settings={editorSettings}
			>
				<div className="pattern-editor_header">
					<Button
						onClick={onClose}
						icon={chevronLeft}
						label="Back"
					>
					</Button>
				</div>

				<div className="pattern-editor_body">
					<div className="pattern-editor_list-view">
						<ListView />
					</div>
					<div className="pattern-editor_content">
						<BlockCanvas height="100%" />
					</div>
					<div className="pattern-editor_sidebar">
						<EditorSidebar />
					</div>
				</div>
			</BlockEditorProvider>
		</div>
	);
};

export default PatternEditor;
