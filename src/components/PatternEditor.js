import {
	BlockEditorProvider,
	BlockList,
	WritingFlow,
	ObserveTyping,
	BlockInspector
} from '@wordpress/block-editor';
import { Panel, PanelBody, TabPanel } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { parse } from '@wordpress/blocks';
import { __experimentalListView as ListView } from '@wordpress/block-editor';
import { __experimentalLibrary as InserterLibrary } from '@wordpress/block-editor';
import { useCallback, useRef } from '@wordpress/element';

const EditorSidebar = ({ pattern }) => {

	const onBlockSelect = ( block ) => {
		console.log( 'Block selected:', block );
	}

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
						name: 'list',
						title: 'List View',
					},
					{
						name: 'add',
						title: 'Add Block',
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
						{tab.name === 'list' && (
							<Panel>
								<p>List View</p>
								<ListView />
							</Panel>
						)}
						{tab.name === 'add' && (
							<Panel>
								<InserterLibrary
									showMostUsedBlocks
									ref={ libraryRef }
									onSelect={ onBlockSelect }/>
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

	const settings = {
		hasFixedToolbar: true,
	};


	return (
		<div className="pattern-manager_editor">
			<BlockEditorProvider
				value={ blocks }
				onInput={ setBlocks }
				onChange={ setBlocks }
				settings={ settings }
			>
				<div className="pattern-editor_header">
					Editor
				</div>

				<div className="pattern-editor_body">
					<div className="pattern-editor_content">
					<WritingFlow>
						<ObserveTyping>
							<BlockList />
						</ObserveTyping>
					</WritingFlow>
					</div>
					<div className="pattern-editor_sidebar">
						<EditorSidebar/>
					</div>
				</div>
			</BlockEditorProvider>
		</div>
	);
};

export default PatternEditor;
