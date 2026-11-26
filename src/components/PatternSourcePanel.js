import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';

import { navigateToPattern } from '../utils/patternNavigation';
import { PLUGIN_ORIGIN_SCHEME } from '../utils/origin';
import {
	addressOf,
	syncEditorAfterConversion,
} from '../utils/patternConversion';
import { PatternConversionDialog } from './PatternConversionDialog';
import {
	usePatternCloudState,
	PatternCloudControls,
} from './PatternCloudPanel';

/**
 * The one line a pattern's `Origin:` is shown as.
 *
 * A bare origin is a cloud name and says so plainly. One carrying a scheme names something
 * that is not on the cloud — today only a pattern a plugin registered — and is said
 * differently, because "originally from twentig/hero" would read as a cloud pattern anyone
 * could go and look at.
 *
 * @param {string} origin The pattern's origin.
 * @return {string} The line to show.
 */
function describeOrigin( origin ) {
	if ( origin.startsWith( PLUGIN_ORIGIN_SCHEME ) ) {
		return sprintf(
			/* translators: %s: the registered pattern this one was copied from, e.g. twentig/hero. */
			__( 'Copied from the plugin pattern %s', 'pattern-builder' ),
			origin.slice( PLUGIN_ORIGIN_SCHEME.length )
		);
	}

	return sprintf(
		/* translators: %s: the pattern this one was copied from, e.g. studio-a/heroes/hero. */
		__( 'Originally from %s', 'pattern-builder' ),
		origin
	);
}

/**
 * Shows where a pattern is stored and converts it to the other storage.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 * @param {string} root0.postType    The pattern's post type.
 */
export const PatternSourcePanel = ( { patternPost, postType } ) => {
	const [ isConverting, setIsConverting ] = useState( false );
	// What converting would touch, while the dialog asking about it is open.
	const [ preview, setPreview ] = useState( null );
	const { createErrorNotice, createSuccessNotice } =
		useDispatch( noticesStore );
	const { onNavigateToEntityRecord, onNavigateToPreviousEntityRecord } =
		useSelect( ( select ) => {
			return {
				onNavigateToEntityRecord:
					select( blockEditorStore ).getSettings()
						.onNavigateToEntityRecord,
				onNavigateToPreviousEntityRecord:
					select( editorStore ).getEditorSettings()
						.onNavigateToPreviousEntityRecord,
			};
		}, [] );

	const isThemePattern = postType === 'pb_pattern';
	const patternType = isThemePattern ? 'theme' : 'user';
	const { state: cloudState, refresh: refreshCloud } = usePatternCloudState(
		patternType,
		patternPost?.id,
		patternPost?.modified
	);

	if ( ! patternPost ) {
		return null;
	}
	const content =
		typeof patternPost.content === 'string'
			? patternPost.content
			: patternPost.content?.raw || '';

	const reportFailure = ( error ) => {
		createErrorNotice(
			error?.message ||
				__( 'The pattern could not be converted.', 'pattern-builder' ),
			{ type: 'snackbar' }
		);
		setIsConverting( false );
	};

	const convert = async () => {
		setIsConverting( true );

		try {
			let converted;

			if ( isThemePattern ) {
				converted = await apiFetch( {
					path: `/pattern-builder/v1/patterns/${ patternPost.id }`,
					method: 'PUT',
					data: { source: 'user' },
				} );
			} else {
				converted = await apiFetch( {
					path: '/pattern-builder/v1/patterns',
					method: 'POST',
					data: { fromWpBlock: patternPost.id },
				} );
			}

			const posts = converted.repointed?.posts ?? [];
			const moved =
				posts.length + ( converted.repointed?.files?.length ?? 0 );

			syncEditorAfterConversion(
				addressOf( {
					id: patternPost.id,
					name: patternPost.name ?? patternPost.id,
					source: patternType,
				} ),
				addressOf( converted ),
				posts,
				!! converted.synced
			);

			if ( moved > 0 ) {
				createSuccessNotice(
					sprintf(
						/* translators: %d: how many places were updated. */
						_n(
							'Pattern converted. %d place that used it now points at the converted pattern.',
							'Pattern converted. %d places that used it now point at the converted pattern.',
							moved,
							'pattern-builder'
						),
						moved
					),
					{ type: 'snackbar' }
				);
			}

			// The pattern as it was is gone, so it comes off the editor's history first:
			// Back from the converted pattern goes to wherever this one was opened from.
			onNavigateToPreviousEntityRecord?.();

			navigateToPattern(
				{
					id: converted.id,
					name: converted.name,
					source: converted.source,
				},
				onNavigateToEntityRecord
			);
		} catch ( error ) {
			setPreview( null );
			reportFailure( error );
		}
	};

	// Asks the server what the conversion reaches. A pattern nothing uses converts straight
	// away, as it always has; anything else is shown first.
	const startConverting = async () => {
		setIsConverting( true );

		try {
			const found = await apiFetch( {
				path: addQueryArgs( '/pattern-builder/v1/conversion', {
					type: patternType,
					id: String( patternPost.id ),
				} ),
			} );

			if (
				found.blockers.length ||
				found.files.length ||
				found.postTotal
			) {
				setPreview( found );
				setIsConverting( false );
				return;
			}
		} catch ( error ) {
			reportFailure( error );
			return;
		}

		convert();
	};

	return (
		<VStack spacing={ 3 }>
			{ isThemePattern ? (
				<>
					<Text variant="muted">
						{ __(
							'This is a Theme Pattern, stored as a file in your current theme directory.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="secondary"
						isBusy={ isConverting }
						disabled={ isConverting }
						onClick={ startConverting }
						hovertip={ __(
							'Converting moves the pattern into the database (exporting its images to the media library), deletes the theme file, and updates the pages and patterns that use it.',
							'pattern-builder'
						) }
					>
						{ __( 'Convert to User Pattern', 'pattern-builder' ) }
					</Button>
				</>
			) : (
				<>
					<Text variant="muted">
						{ __(
							'This is a User Pattern, stored in the database, and works across themes.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="secondary"
						isBusy={ isConverting }
						disabled={ isConverting }
						onClick={ startConverting }
						hovertip={ __(
							'Converting writes the pattern into a file in the active theme (importing its images as theme assets), deletes the database copy, and updates everything that uses it. The last saved version is converted.',
							'pattern-builder'
						) }
					>
						{ __( 'Convert to Theme Pattern', 'pattern-builder' ) }
					</Button>
				</>
			) }

			{ preview && (
				<PatternConversionDialog
					preview={ preview }
					toTheme={ ! isThemePattern }
					isBusy={ isConverting }
					onConfirm={ convert }
					onCancel={ () => setPreview( null ) }
				/>
			) }

			{ !! patternPost.origin && (
				<Text variant="muted" size="12px">
					{ describeOrigin( patternPost.origin ) }
				</Text>
			) }

			{ cloudState?.connected && (
				<div className="pattern-builder-source__cloud">
					<PatternCloudControls
						state={ cloudState }
						onRefresh={ refreshCloud }
						patternType={ patternType }
						patternId={ patternPost?.id }
						content={ content }
					/>
				</div>
			) }
		</VStack>
	);
};
