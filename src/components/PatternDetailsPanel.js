import { __, _x } from '@wordpress/i18n';
import {
	Button,
	Flex,
	FlexItem,
	PanelBody,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';

import { PatternSourcePanel } from './PatternSourcePanel';
import { PatternSyncedStatusPanel } from './PatternSyncedStatusPanel';
import { PatternMetadataPanel } from './PatternMetadataPanel';
import { PatternAssociationsPanel } from './PatternAssociationsPanel';
import { PatternCloudPanelBody } from './PatternCloudPanel';

/**
 * The browse screen's details sidebar for the selected pattern — the same
 * panels the editor shows for a pattern document (Source, Synced Status,
 * and for theme patterns Metadata and Associations), staged on the entity
 * and persisted by the Save button; Edit opens the pattern's editor.
 *
 * Mount with `key={pattern.id}` — the metadata panels keep local input
 * state seeded from the record.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.pattern The selected pattern (AbstractPattern).
 * @param {Function} props.onEdit  Called with the pattern to open its editor.
 * @param {Function} props.onSaved Called after a successful save.
 */
export const PatternDetailsPanel = ( { pattern, onEdit, onSaved } ) => {
	const postType = pattern.source === 'theme' ? 'pb_pattern' : 'wp_block';
	const isThemePattern = postType === 'pb_pattern';

	const { record, hasEdits, isSaving } = useSelect(
		( select ) => {
			const {
				getEditedEntityRecord,
				hasEditsForEntityRecord,
				isSavingEntityRecord,
			} = select( coreStore );

			return {
				record: getEditedEntityRecord(
					'postType',
					postType,
					pattern.id
				),
				hasEdits: hasEditsForEntityRecord(
					'postType',
					postType,
					pattern.id
				),
				isSaving: isSavingEntityRecord(
					'postType',
					postType,
					pattern.id
				),
			};
		},
		[ postType, pattern.id ]
	);

	const { saveEditedEntityRecord } = useDispatch( coreStore );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const isLoaded = !! record && Object.keys( record ).length > 0;

	const save = async () => {
		try {
			await saveEditedEntityRecord( 'postType', postType, pattern.id, {
				throwOnError: true,
			} );
			createSuccessNotice( __( 'Pattern saved.', 'pattern-builder' ), {
				type: 'snackbar',
			} );

			if ( onSaved ) {
				onSaved();
			}
		} catch ( error ) {
			createErrorNotice(
				error?.message ||
					__( 'The pattern could not be saved.', 'pattern-builder' ),
				{ type: 'snackbar' }
			);
		}
	};

	return (
		<div className="pattern-builder-details">
			<div className="pattern-builder-details__header">
				<Heading level={ 2 } size={ 16 } truncate>
					{ pattern.title }
				</Heading>
				<Text variant="muted" size="12px">
					{ isThemePattern
						? _x( 'Theme Pattern', 'UI String', 'pattern-builder' )
						: _x( 'User Pattern', 'UI String', 'pattern-builder' ) }
				</Text>
			</div>

			<div className="pattern-builder-details__panels">
				{ ! isLoaded && (
					<div className="pattern-builder-details__loading">
						<Spinner />
					</div>
				) }

				{ isLoaded && (
					<>
						<PanelBody
							title={ _x(
								'Pattern Source',
								'UI String',
								'pattern-builder'
							) }
							initialOpen
						>
							<PatternSourcePanel
								patternPost={ record }
								postType={ postType }
							/>
						</PanelBody>

						<PanelBody
							title={ _x(
								'Pattern Synced Status',
								'UI String',
								'pattern-builder'
							) }
							initialOpen
						>
							<PatternSyncedStatusPanel
								patternPost={ record }
								postType={ postType }
							/>
						</PanelBody>

						<PatternCloudPanelBody
							patternType={ isThemePattern ? 'theme' : 'user' }
							patternId={ pattern.id }
							refreshKey={ record?.modified }
						/>

						{ isThemePattern && (
							<PanelBody
								title={ _x(
									'Pattern Metadata',
									'UI String',
									'pattern-builder'
								) }
								initialOpen
							>
								<PatternMetadataPanel patternPost={ record } />
							</PanelBody>
						) }

						{ isThemePattern && (
							<PanelBody
								title={ _x(
									'Pattern Associations',
									'UI String',
									'pattern-builder'
								) }
								initialOpen={ false }
							>
								<PatternAssociationsPanel
									patternPost={ record }
								/>
							</PanelBody>
						) }
					</>
				) }
			</div>

			<Flex className="pattern-builder-details__actions" gap={ 2 }>
				<FlexItem isBlock>
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ ! hasEdits || isSaving }
						isBusy={ isSaving }
						onClick={ save }
						className="pattern-builder-details__action-button"
					>
						{ __( 'Save', 'pattern-builder' ) }
					</Button>
				</FlexItem>
				<FlexItem isBlock>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={ () => onEdit( pattern ) }
						className="pattern-builder-details__action-button"
					>
						{ __( 'Edit', 'pattern-builder' ) }
					</Button>
				</FlexItem>
			</Flex>
		</div>
	);
};
