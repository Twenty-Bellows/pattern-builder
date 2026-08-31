import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { cloudUpload } from '@wordpress/icons';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';
import { humanTimeDiff } from '@wordpress/date';

import {
	findInvalidBlocks,
	describeInvalidBlocks,
} from '../utils/blockValidity';

const BASE = '/pattern-builder/v1/cloud';

/**
 * One pattern's cloud standing: null while loading, then the
 * /cloud/pattern-state payload.
 *
 * @param {string}        patternType 'theme' or 'user'.
 * @param {string|number} patternId   Local pattern identifier.
 * @param {*}             refreshKey  Changing this value re-fetches.
 * @return {Object} { state, refresh }
 */
export function usePatternCloudState( patternType, patternId, refreshKey ) {
	const [ state, setState ] = useState( null );

	const refresh = useCallback( () => {
		if ( ! patternId ) {
			return;
		}
		apiFetch( {
			path: addQueryArgs( `${ BASE }/pattern-state`, {
				patternType,
				patternId,
			} ),
		} )
			.then( setState )
			.catch( () => setState( { connected: false } ) );
	}, [ patternType, patternId ] );

	useEffect( () => {
		setState( null );
		refresh();
	}, [ refresh, refreshKey ] );

	return { state, refresh };
}

/**
 * The Cloud panel's controls: upload, update, or an up-to-date line.
 *
 * Uploading is gated on the editor's own block validation. Markup a block
 * type would not have written itself renders correctly here — it is only
 * when an editor opens it that it reads as "unexpected or invalid content",
 * and by then it is on somebody else's site. That check can only happen in
 * a browser (a block's `save()` is JavaScript, so no server can run it), and
 * this panel is already in one with the block types loaded.
 *
 * @param {Object}        props             Component props.
 * @param {Object}        props.state       State from usePatternCloudState.
 * @param {Function}      props.onRefresh   Re-fetches the state after upload.
 * @param {string}        props.patternType 'theme' or 'user'.
 * @param {string|number} props.patternId   Local pattern identifier.
 * @param {string}        props.content     The pattern's saved block markup.
 */
export function PatternCloudControls( {
	state,
	onRefresh,
	patternType,
	patternId,
	content,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { createSuccessNotice } = useDispatch( noticesStore );

	// The saved markup is what the server uploads, so that is what gets
	// checked — not whatever is unsaved in the canvas.
	const invalid = useMemo( () => findInvalidBlocks( content ), [ content ] );

	if ( ! state ) {
		return <Spinner />;
	}

	const isUpdate = state.linked;

	// Downloaded from somebody else's cloud pattern: only its owner can
	// update it, so there is nothing here to offer.
	const isTheirs = state.linked && ! state.owned;

	const upload = () => {
		if ( busy || invalid.length ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `${ BASE }/upload`,
			method: 'POST',
			data: { patternType, patternId },
		} )
			.then( () => {
				setBusy( false );
				createSuccessNotice(
					isUpdate
						? __( 'Cloud copy updated.', 'pattern-builder' )
						: __(
								'Pattern uploaded to your cloud library.',
								'pattern-builder'
						  ),
					{ type: 'snackbar' }
				);
				onRefresh();
			} )
			.catch( ( err ) => {
				setBusy( false );
				// The service names what it objected to (an image it can't
				// reach, say); say it too, or the message can't be acted on.
				const details = err.data?.violations?.length
					? ' ' + err.data.violations.join( ' ' )
					: '';
				setError(
					( err.message ||
						__(
							'The upload failed. Try again.',
							'pattern-builder'
						) ) + details
				);
			} );
	};

	const uploadedAgo =
		state.uploadedAt > 0
			? sprintf(
					/* translators: %s: relative time, e.g. "2 hours ago". */
					__( 'Uploaded %s.', 'pattern-builder' ),
					humanTimeDiff( state.uploadedAt * 1000, Date.now() )
			  )
			: '';

	const blockedByInvalidBlocks = invalid.length > 0;

	return (
		<VStack spacing={ 3 }>
			{ isTheirs && (
				<Text variant="muted">
					{ __(
						'Downloaded from another account’s pattern. Changes stay on this site — the cloud copy is not yours to update.',
						'pattern-builder'
					) }
				</Text>
			) }

			{ ! state.linked && (
				<>
					<Text variant="muted">
						{ __(
							'Not in your cloud library yet.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="primary"
						icon={ cloudUpload }
						isBusy={ busy }
						disabled={ busy || blockedByInvalidBlocks }
						onClick={ upload }
					>
						{ __( 'Upload to the cloud', 'pattern-builder' ) }
					</Button>
				</>
			) }

			{ state.linked && state.owned && state.changed && (
				<>
					<Text variant="muted">
						{ __(
							'This pattern has changed since it was uploaded.',
							'pattern-builder'
						) }
					</Text>
					<Button
						variant="primary"
						icon={ cloudUpload }
						isBusy={ busy }
						disabled={ busy || blockedByInvalidBlocks }
						onClick={ upload }
					>
						{ __(
							'Update pattern on the cloud',
							'pattern-builder'
						) }
					</Button>
				</>
			) }

			{ state.linked && state.owned && ! state.changed && (
				<Text variant="muted">
					{ __(
						'Up to date in your cloud library.',
						'pattern-builder'
					) }
					{ uploadedAgo ? ` ${ uploadedAgo }` : '' }
				</Text>
			) }

			{ blockedByInvalidBlocks && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: comma separated block names, e.g. "heading (2), list". */
						__(
							'This pattern cannot be uploaded: %s would open as "unexpected or invalid content" in the editor. Edit the pattern and use Attempt Block Recovery on the blocks marked in red, then save.',
							'pattern-builder'
						),
						describeInvalidBlocks( invalid )
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</VStack>
	);
}
