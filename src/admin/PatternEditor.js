import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';
import { EditorProvider, store as editorStore } from '@wordpress/editor';
import {
	BlockCanvas,
	BlockInspector,
	BlockTools,
	BlockEditorKeyboardShortcuts,
} from '@wordpress/block-editor';
import {
	Button,
	Popover,
	SnackbarList,
	Spinner,
	TabPanel,
	TextControl,
	PanelBody,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { ShortcutProvider } from '@wordpress/keyboard-shortcuts';
import {
	arrowLeft,
	undo as undoIcon,
	redo as redoIcon,
} from '@wordpress/icons';

import { PatternSourcePanel } from '../components/PatternSourcePanel';
import { PatternSyncedStatusPanel } from '../components/PatternSyncedStatusPanel';
import { PatternMetadataPanel } from '../components/PatternMetadataPanel';
import { PatternAssociationsPanel } from '../components/PatternAssociationsPanel';
import { BlockBindingsPanel } from '../components/BlockBindingsPanel';

/**
 * Snackbar notices, rendered from the notices store.
 */
function EditorNotices() {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className="pattern-builder-editor__notices"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}

/**
 * The editor chrome: header bar, canvas, and settings sidebar.
 *
 * Rendered inside EditorProvider, so the editor and block-editor stores are
 * bound to the pattern entity.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.pattern  The pattern's (edited) entity record.
 * @param {Object}   props.settings Block editor settings.
 * @param {Function} props.onBack   Returns to the pattern browser.
 */
function EditorChrome( { pattern, settings, onBack } ) {
	const { savePost } = useDispatch( editorStore );
	const { undo, redo } = useDispatch( coreStore );
	const { editEntityRecord } = useDispatch( coreStore );

	const { isDirty, isSaving, hasUndo, hasRedo } = useSelect( ( select ) => {
		return {
			isDirty: select( editorStore ).isEditedPostDirty(),
			isSaving: select( editorStore ).isSavingPost(),
			hasUndo: select( coreStore ).hasUndo(),
			hasRedo: select( coreStore ).hasRedo(),
		};
	}, [] );

	const onKeyDown = ( event ) => {
		const modifier = event.metaKey || event.ctrlKey;

		if ( ! modifier ) {
			return;
		}

		if ( event.key === 's' ) {
			event.preventDefault();
			if ( isDirty && ! isSaving ) {
				savePost();
			}
		}

		if ( event.key === 'z' && ! event.shiftKey ) {
			event.preventDefault();
			undo();
		}

		if ( ( event.key === 'z' && event.shiftKey ) || event.key === 'y' ) {
			event.preventDefault();
			redo();
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="pattern-builder-editor" onKeyDown={ onKeyDown }>
			<div className="pattern-builder-editor__header">
				<HStack alignment="edge">
					<HStack alignment="left" spacing={ 2 } expanded={ false }>
						<Button
							icon={ arrowLeft }
							label={ __(
								'Back to patterns',
								'pattern-builder'
							) }
							onClick={ onBack }
						/>
						<Text weight={ 600 } truncate>
							{ pattern.title?.raw ?? pattern.title ?? '' }
						</Text>
						{ pattern.synced && (
							<Text variant="muted">
								{ __( '(Synced)', 'pattern-builder' ) }
							</Text>
						) }
					</HStack>
					<HStack alignment="right" spacing={ 2 } expanded={ false }>
						<Button
							icon={ undoIcon }
							label={ __( 'Undo', 'pattern-builder' ) }
							onClick={ undo }
							disabled={ ! hasUndo }
						/>
						<Button
							icon={ redoIcon }
							label={ __( 'Redo', 'pattern-builder' ) }
							onClick={ redo }
							disabled={ ! hasRedo }
						/>
						<Button
							variant="primary"
							isBusy={ isSaving }
							disabled={ ! isDirty || isSaving }
							onClick={ () => savePost() }
						>
							{ isSaving
								? __( 'Saving…', 'pattern-builder' )
								: __( 'Save', 'pattern-builder' ) }
						</Button>
					</HStack>
				</HStack>
			</div>

			<div className="pattern-builder-editor__body">
				<div className="pattern-builder-editor__canvas">
					<BlockEditorKeyboardShortcuts.Register />
					<BlockTools>
						<BlockCanvas height="100%" styles={ settings.styles } />
					</BlockTools>
				</div>

				<div className="pattern-builder-editor__sidebar">
					<TabPanel
						tabs={ [
							{
								name: 'pattern',
								title: __( 'Pattern', 'pattern-builder' ),
							},
							{
								name: 'block',
								title: __( 'Block', 'pattern-builder' ),
							},
						] }
					>
						{ ( tab ) =>
							tab.name === 'pattern' ? (
								<>
									<PanelBody
										title={ __(
											'Title',
											'pattern-builder'
										) }
									>
										<TextControl
											__nextHasNoMarginBottom
											__next40pxDefaultSize
											label={ __(
												'Pattern title',
												'pattern-builder'
											) }
											hideLabelFromVision
											value={
												pattern.title?.raw ??
												pattern.title ??
												''
											}
											onChange={ ( value ) =>
												editEntityRecord(
													'postType',
													'pb_pattern',
													pattern.id,
													{ title: value }
												)
											}
										/>
									</PanelBody>
									<PanelBody
										title={ __(
											'Synced Status',
											'pattern-builder'
										) }
									>
										<PatternSyncedStatusPanel
											patternPost={ pattern }
											postType="pb_pattern"
										/>
									</PanelBody>
									<PanelBody
										title={ __(
											'Metadata',
											'pattern-builder'
										) }
										initialOpen={ false }
									>
										<PatternMetadataPanel
											patternPost={ pattern }
										/>
									</PanelBody>
									<PanelBody
										title={ __(
											'Associations',
											'pattern-builder'
										) }
										initialOpen={ false }
									>
										<PatternAssociationsPanel
											patternPost={ pattern }
										/>
									</PanelBody>
									<PanelBody
										title={ __(
											'Bindings',
											'pattern-builder'
										) }
										initialOpen={ false }
									>
										<BlockBindingsPanel />
									</PanelBody>
									<PanelBody
										title={ __(
											'Storage',
											'pattern-builder'
										) }
										initialOpen={ false }
									>
										<PatternSourcePanel
											patternPost={ pattern }
											postType="pb_pattern"
										/>
									</PanelBody>
								</>
							) : (
								<BlockInspector />
							)
						}
					</TabPanel>
				</div>
			</div>

			<EditorNotices />
			<Popover.Slot />
		</div>
	);
}

/**
 * Loads a theme pattern entity and mounts the editor on it.
 *
 * @param {Object}   props                Component props.
 * @param {string}   props.patternId      The pattern's entity id (its name).
 * @param {Object}   props.editorSettings Block editor settings from the server.
 * @param {Function} props.onBack         Returns to the pattern browser.
 */
export function PatternEditor( { patternId, editorSettings, onBack } ) {
	const { record, editedRecord, hasResolved } = useSelect(
		( select ) => {
			const args = [ 'postType', 'pb_pattern', patternId ];
			return {
				record: select( coreStore ).getEntityRecord( ...args ),
				editedRecord: select( coreStore ).getEditedEntityRecord(
					...args
				),
				hasResolved: select( coreStore ).hasFinishedResolution(
					'getEntityRecord',
					args
				),
			};
		},
		[ patternId ]
	);

	if ( ! hasResolved ) {
		return (
			<div className="pattern-builder-editor__loading">
				<Spinner />
			</div>
		);
	}

	if ( ! record ) {
		return (
			<div className="pattern-builder-editor__loading">
				<p>
					{ __(
						'This theme pattern could not be found.',
						'pattern-builder'
					) }
				</p>
				<Button variant="secondary" onClick={ onBack }>
					{ __( 'Back to patterns', 'pattern-builder' ) }
				</Button>
			</div>
		);
	}

	return (
		<ShortcutProvider>
			{ /* The global registry, the way core's own editor screens run. */ }
			<EditorProvider
				post={ record }
				settings={ editorSettings }
				useSubRegistry={ false }
			>
				<EditorChrome
					pattern={ editedRecord }
					settings={ editorSettings }
					onBack={ onBack }
				/>
			</EditorProvider>
		</ShortcutProvider>
	);
}
