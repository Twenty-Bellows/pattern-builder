import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { navigateToPattern } from '../utils/patternNavigation';

/**
 * Hand the browser a file without a round trip to the server.
 *
 * @param {string} filename Download filename.
 * @param {string} contents File contents.
 * @param {string} mime     MIME type.
 */
function downloadFile( filename, contents, mime ) {
	const url = window.URL.createObjectURL(
		new window.Blob( [ contents ], { type: mime } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
}

/**
 * Whole-pattern actions: duplicate, delete, and the two exports.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.patternPost The pattern's entity record.
 * @param {string}   props.postType    'pb_pattern' or 'wp_block'.
 * @param {Function} props.onChanged   Called after the pattern list changes.
 */
export const PatternActionsPanel = ( { patternPost, postType, onChanged } ) => {
	const [ busy, setBusy ] = useState( '' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const isThemePattern = postType === 'pb_pattern';
	const title =
		typeof patternPost.title === 'object'
			? patternPost.title?.raw || ''
			: patternPost.title || '';
	const content =
		typeof patternPost.content === 'object'
			? patternPost.content?.raw || ''
			: patternPost.content || '';
	const slug = isThemePattern
		? String( patternPost.name || '' )
				.split( '/' )
				.slice( 1 )
				.join( '/' )
		: patternPost.slug || String( patternPost.id );

	const fail = ( error, fallback ) => {
		setBusy( '' );
		createErrorNotice( error?.message || fallback, { type: 'snackbar' } );
	};

	const duplicate = () => {
		setBusy( 'duplicate' );
		const copyTitle = sprintf(
			/* translators: %s: pattern title. */
			__( '%s (copy)', 'pattern-builder' ),
			title
		);

		const request = isThemePattern
			? {
					path: '/pattern-builder/v1/patterns',
					method: 'POST',
					data: {
						title: copyTitle,
						content,
						description: patternPost.description || '',
						categories: patternPost.categories || [],
						keywords: patternPost.keywords || [],
						synced: !! patternPost.synced,
					},
			  }
			: {
					path: '/wp/v2/blocks',
					method: 'POST',
					data: {
						title: copyTitle,
						content,
						status: 'publish',
						meta: patternPost.meta?.wp_pattern_sync_status
							? {
									wp_pattern_sync_status:
										patternPost.meta.wp_pattern_sync_status,
							  }
							: undefined,
					},
			  };

		apiFetch( request )
			.then( ( created ) => {
				setBusy( '' );
				createSuccessNotice(
					__( 'Pattern duplicated.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
				if ( onChanged ) {
					onChanged();
				}
				navigateToPattern( {
					id: created.id,
					name: created.name,
					source: isThemePattern ? 'theme' : 'user',
				} );
			} )
			.catch( ( error ) =>
				fail(
					error,
					__(
						'The pattern could not be duplicated.',
						'pattern-builder'
					)
				)
			);
	};

	const remove = () => {
		const message = __(
			'Delete this pattern? This cannot be undone.',
			'pattern-builder'
		);
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm( message );

		if ( ! confirmed ) {
			return;
		}

		setBusy( 'delete' );
		apiFetch( {
			path: isThemePattern
				? `/pattern-builder/v1/patterns/${ encodeURIComponent(
						patternPost.id
				  ) }`
				: `/wp/v2/blocks/${ patternPost.id }?force=true`,
			method: 'DELETE',
		} )
			.then( () => {
				setBusy( '' );
				createSuccessNotice(
					__( 'Pattern deleted.', 'pattern-builder' ),
					{ type: 'snackbar' }
				);
				if ( onChanged ) {
					onChanged();
				}
			} )
			.catch( ( error ) =>
				fail(
					error,
					__( 'The pattern could not be deleted.', 'pattern-builder' )
				)
			);
	};

	const exportJson = () => {
		downloadFile(
			`${ slug || 'pattern' }.json`,
			JSON.stringify(
				{
					title,
					slug,
					description: patternPost.description || '',
					categories: patternPost.categories || [],
					keywords: patternPost.keywords || [],
					viewportWidth: patternPost.viewportWidth || undefined,
					synced: !! patternPost.synced,
					content,
				},
				null,
				2
			),
			'application/json'
		);
	};

	const exportMarkup = () =>
		downloadFile( `${ slug || 'pattern' }.html`, content, 'text/html' );

	return (
		<VStack spacing={ 2 }>
			<Button
				variant="secondary"
				isBusy={ busy === 'duplicate' }
				disabled={ !! busy }
				onClick={ duplicate }
			>
				{ __( 'Duplicate', 'pattern-builder' ) }
			</Button>
			<Button variant="secondary" onClick={ exportJson }>
				{ __( 'Export JSON', 'pattern-builder' ) }
			</Button>
			<Button variant="secondary" onClick={ exportMarkup }>
				{ __( 'Export markup', 'pattern-builder' ) }
			</Button>
			<Button
				variant="tertiary"
				isDestructive
				isBusy={ busy === 'delete' }
				disabled={ !! busy }
				onClick={ remove }
			>
				{ __( 'Delete', 'pattern-builder' ) }
			</Button>
		</VStack>
	);
};
