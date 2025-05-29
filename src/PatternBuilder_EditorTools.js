/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { Modal, Button } from '@wordpress/components';
import { __, _x } from '@wordpress/i18n';
import { tool, widget } from '@wordpress/icons';
import { useState, useEffect, createContext, useContext } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { BlockControls } from '@wordpress/block-editor';
import { ToolbarGroup, ToolbarButton } from '@wordpress/components';
import { edit } from '@wordpress/icons';

import PatternBuilder from './PatternBuilder';
import PatternSearch from './components/PatternSearch';

// Create context for Pattern Builder modal state
const PatternBuilderContext = createContext();

// Global state for Pattern Builder modal (for access from HOC)
let globalOpenPatternBuilder = null;

const PatternBuilderModal = ( { onRequestClose, editingPatternId } ) => {
	return (
		<Modal
			title={ _x( 'Pattern Builder', 'UI String', 'pattern-builder' ) }
			className="pattern-builder__modal"
			onRequestClose={ onRequestClose }
			isFullScreen
		>
			<PatternBuilder editingPatternId={ editingPatternId } />
		</Modal>
	);
};

function PatternBuilderProvider( { children } ) {
	const [ isPatternBuilderOpen, setIsPatternBuilderOpen ] = useState( false );
	const [ editingPatternId, setEditingPatternId ] = useState( null );

	const openPatternBuilder = ( patternId = null ) => {
		setEditingPatternId( patternId );
		setIsPatternBuilderOpen( true );
	};

	const closePatternBuilder = () => {
		setIsPatternBuilderOpen( false );
		setEditingPatternId( null );
		dispatch('pattern-builder').setActivePattern(null);
	};

	// Set global function for access from HOC
	useEffect( () => {
		globalOpenPatternBuilder = openPatternBuilder;
		return () => {
			globalOpenPatternBuilder = null;
		};
	}, [] );

	return (
		<PatternBuilderContext.Provider value={{ openPatternBuilder, closePatternBuilder, isPatternBuilderOpen, editingPatternId }}>
			{ children }
			{ isPatternBuilderOpen && (
				<PatternBuilderModal
					onRequestClose={ closePatternBuilder }
					editingPatternId={ editingPatternId }
				/>
			) }
		</PatternBuilderContext.Provider>
	);
}

export default function PatternBuilderEditorTools() {
	return (
		<PatternBuilderProvider>
			<PatternBuilderUI />
		</PatternBuilderProvider>
	);
}

function PatternBuilderUI() {
	const { openPatternBuilder } = useContext( PatternBuilderContext );

	return (
		<>
			<PluginSidebarMoreMenuItem
				target="pattern-builder-sidebar"
				icon={ tool }
			>
				{ _x( 'Pattern Builder', 'UI String', 'pattern-builder' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				className='pattern-builder__editor-sidebar'
				name="pattern-builder-sidebar"
				icon={ widget }
				title={ _x(
					'Patterns',
					'UI String',
					'pattern-builder'
				) }
			>

				<Button
					variant='primary'
					icon={widget}
					onClick={() => openPatternBuilder()}
					style={{width: '100%'}}
				>
					{__(
						'Manage Patterns',
						'pattern-builder'
					)}
				</Button>
				<PatternSearch />
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'pattern-builder', {
	render: PatternBuilderEditorTools,
} );

// Add custom toolbar controls for editing pb_block patterns
const withEditOriginalControl = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		const { name, attributes } = props;

		// Only add controls to core/block blocks that reference pb_blocks
		if ( name !== 'core/block' || ! attributes.ref ) {
			return <BlockEdit { ...props } />;
		}

		return (
			<>
				<BlockEdit { ...props } />
				<BlockControls group="other">
					<ToolbarGroup>
						<ToolbarButton
							icon={ edit }
							label={ __( 'Edit Pattern', 'pattern-builder' ) }
							onClick={ () => {
								// Open the Pattern Builder modal with this pattern
								if ( globalOpenPatternBuilder ) {
									globalOpenPatternBuilder( attributes.ref );
								}
							} }
						/>
					</ToolbarGroup>
				</BlockControls>
			</>
		);
	};
}, 'withEditOriginalControl' );

addFilter(
	'editor.BlockEdit',
	'pattern-builder/edit-original-control',
	withEditOriginalControl
);
