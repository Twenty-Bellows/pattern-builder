import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	PanelBody,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import {
	downloadPatternJson,
	downloadPatternMarkup,
} from '../utils/patternExport';

/**
 * The browse screen's details sidebar for a pattern a plugin registered. There is no entity
 * behind one and no route that writes it, so this shows what the plugin declared and offers
 * the only thing that can change it: a copy this site owns.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.pattern  The selected plugin pattern, or null for the empty state.
 * @param {Function} props.onEdit   Called with a pattern to open its editor.
 * @param {Function} props.onCopied Called after a copy lands, to re-read the site's patterns.
 */
export const PluginPatternDetailsPanel = ( { pattern, onEdit, onCopied } ) => {
	const [ busy, setBusy ] = useState( '' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	if ( ! pattern ) {
		return (
			<div className="pattern-builder-details is-empty">
				<Text variant="muted">
					{ __( 'No pattern selected.', 'pattern-builder' ) }
				</Text>
			</div>
		);
	}

	const copy = () => {
		setBusy( 'copy' );

		apiFetch( {
			path: '/pattern-builder/v1/patterns',
			method: 'POST',
			data: { fromPlugin: pattern.name },
		} )
			.then( ( created ) => {
				setBusy( '' );

				const brought = ( created.copied || [] ).length;
				const edit = [
					{
						label: __( 'Edit', 'pattern-builder' ),
						onClick: () => onEdit( created ),
					},
				];

				if ( ! created.created ) {
					createSuccessNotice(
						__(
							'Already in your theme — opening that copy.',
							'pattern-builder'
						),
						{ type: 'snackbar', actions: edit }
					);
				} else {
					createSuccessNotice(
						brought > 1
							? sprintf(
									/* translators: %d: how many patterns were written, the copy and the ones it places. */
									__(
										'Copied into your theme, with the %d patterns it uses.',
										'pattern-builder'
									),
									brought - 1
							  )
							: __(
									'Copied into your theme.',
									'pattern-builder'
							  ),
						{ type: 'snackbar', actions: edit }
					);
				}

				if ( ( created.unresolved || [] ).length ) {
					createErrorNotice(
						sprintf(
							/* translators: %s: comma-separated pattern names. */
							__(
								'The copy places patterns nothing on this site registers, so they render as nothing: %s',
								'pattern-builder'
							),
							created.unresolved.join( ', ' )
						),
						{ type: 'snackbar' }
					);
				}

				if ( onCopied ) {
					onCopied();
				}
			} )
			.catch( ( error ) => {
				setBusy( '' );
				createErrorNotice(
					error?.message ||
						__(
							'The pattern could not be copied.',
							'pattern-builder'
						),
					{ type: 'snackbar' }
				);
			} );
	};

	const exportable = {
		title: pattern.title,
		slug: pattern.name.split( '/' ).slice( 1 ).join( '/' ),
		description: pattern.description || '',
		categories: pattern.categories || [],
		keywords: pattern.keywords || [],
		viewportWidth: pattern.viewportWidth || undefined,
		synced: false,
		content: pattern.content,
	};

	return (
		<div className="pattern-builder-details">
			<div className="pattern-builder-details__header">
				<Heading level={ 2 } size={ 16 } truncate>
					{ pattern.title }
				</Heading>
				<Text>{ pattern.description }</Text>
			</div>

			<div className="pattern-builder-details__panels">
				<PanelBody
					title={ __( 'Pattern Source', 'pattern-builder' ) }
					initialOpen
				>
					<VStack spacing={ 3 }>
						<Text variant="muted">
							{ pattern.plugin
								? sprintf(
										/* translators: %s: what registered the pattern, e.g. Twentig. */
										__(
											'This pattern comes from %s. It is registered in PHP rather than stored on this site, so it can be used and copied but not changed here.',
											'pattern-builder'
										),
										pattern.plugin
								  )
								: __(
										'This pattern is registered in PHP by a plugin rather than stored on this site, so it can be used and copied but not changed here.',
										'pattern-builder'
								  ) }
						</Text>
						<Text variant="muted" size="12px">
							{ pattern.name }
						</Text>
					</VStack>
				</PanelBody>

				<PanelBody
					title={ __( 'Make a Copy', 'pattern-builder' ) }
					initialOpen
				>
					<VStack spacing={ 2 }>
						<Button
							__next40pxDefaultSize
							variant="primary"
							isBusy={ 'copy' === busy }
							disabled={ !! busy }
							onClick={ copy }
						>
							{ __( 'Copy to Theme', 'pattern-builder' ) }
						</Button>
						<Text variant="muted" size="12px">
							{ __(
								'A copy is yours: edit it, and upload it to a cloud collection from its own details. The images it uses come into the theme with it, and so do the patterns it places — a copy of one you already have is used rather than made again.',
								'pattern-builder'
							) }
						</Text>
					</VStack>
				</PanelBody>

				<PanelBody
					title={ __( 'Pattern Actions', 'pattern-builder' ) }
					initialOpen={ false }
				>
					<VStack spacing={ 2 }>
						<Button
							variant="secondary"
							onClick={ () => downloadPatternJson( exportable ) }
						>
							{ __( 'Export JSON', 'pattern-builder' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ () =>
								downloadPatternMarkup( exportable )
							}
						>
							{ __( 'Export markup', 'pattern-builder' ) }
						</Button>
					</VStack>
				</PanelBody>
			</div>
		</div>
	);
};
