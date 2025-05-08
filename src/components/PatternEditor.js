import {
	BlockEditorProvider,
	BlockInspector
} from '@wordpress/block-editor';
import { Panel, TabPanel } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { parse } from '@wordpress/blocks';
import { __experimentalListView as ListView } from '@wordpress/block-editor';
import { __experimentalLibrary as InserterLibrary } from '@wordpress/block-editor';
import { useCallback, useRef } from '@wordpress/element';
import { BlockToolbar, BlockCanvas } from '@wordpress/block-editor';
import { SlotFillProvider } from '@wordpress/components';
import { Slot } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import { getEditorSettings } from '../resolvers';

const EditorSidebar = ({ pattern }) => {

	const libraryRef = useRef();

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

export const PatternEditor = ({ pattern }) => {

	const [ blocks, setBlocks ] = useState( parse( pattern.content ) );
	const [ editorSettings, setEditorSettings ] = useState( null );

	// TODO: Optimize me
	useEffect(() => {
		getEditorSettings()
			.then((data) => {
				setEditorSettings(data);
			});
	}, []);

	if ( ! editorSettings) {
		return (
			<div className="pattern-manager_editor">
				Loading...
			</div>
		);
	}

	return (
		<div className="pattern-manager_editor">
			<SlotFillProvider>
				<BlockEditorProvider
					value={blocks}
					onInput={setBlocks}
					onChange={setBlocks}
					settings={editorSettings}
				>
					<div className="pattern-editor_header">
						Editor
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
			</SlotFillProvider>
		</div>
	);
};

export default PatternEditor;
