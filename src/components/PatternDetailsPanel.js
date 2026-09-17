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
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

import { PatternSourcePanel } from './PatternSourcePanel';
import { PatternActionsPanel } from './PatternActionsPanel';
import { PatternPhpNotice } from './PatternPhpNotice';

/**
 * The browse screen's details sidebar for the selected pattern: where it is stored, and
 * what can be done with it, under an Edit action that opens the pattern's own editor.
 *
 * Both panels act on the pattern through the REST API as they are used, rather than
 * staging an edit on the entity, so there is nothing here to save. Everything that is
 * edited rather than acted on is edited in the pattern's editor.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.pattern The selected pattern, or null for the empty state.
 * @param {Function} props.onEdit  Called with the pattern to open its editor.
 * @param {Function} props.onSaved Called after the pattern list changes.
 */
export const PatternDetailsPanel = ( { pattern, onEdit, onSaved } ) => {
	const postType = pattern?.source === 'theme' ? 'pb_pattern' : 'wp_block';

	const record = useSelect(
		( select ) =>
			pattern?.id
				? select( coreStore ).getEditedEntityRecord(
						'postType',
						postType,
						pattern.id
				  )
				: null,
		[ postType, pattern?.id ]
	);

	const isLoaded = !! record && Object.keys( record ).length > 0;

	if ( ! pattern ) {
		return (
			<div className="pattern-builder-details is-empty">
				<Text variant="muted">
					{ __( 'No pattern selected.', 'pattern-builder' ) }
				</Text>
			</div>
		);
	}

	return (
		<div className="pattern-builder-details">
			<div className="pattern-builder-details__header">
				<Heading level={ 2 } size={ 16 } truncate>
					{ pattern.title }
				</Heading>
				<Text>{ pattern.description }</Text>

				<Flex className="pattern-builder-details__actions" gap={ 2 }>
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

			<div className="pattern-builder-details__panels">
				<PatternPhpNotice hasCustomPhp={ !! record?.hasCustomPhp } />
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
								'Pattern Actions',
								'UI String',
								'pattern-builder'
							) }
							initialOpen={ false }
						>
							<PatternActionsPanel
								patternPost={ record }
								postType={ postType }
								onChanged={ onSaved }
							/>
						</PanelBody>
					</>
				) }
			</div>
		</div>
	);
};
