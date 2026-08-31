import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
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
 * @param {Object}        props             Component props.
 * @param {Object}        props.state       State from usePatternCloudState.
 * @param {Function}      props.onRefresh   Re-fetches the state after upload.
 * @param {string}        props.patternType 'theme' or 'user'.
 * @param {string|number} props.patternId   Local pattern identifier.
 */
export function PatternCloudControls( {
	state,
	onRefresh,
	patternType,
	patternId,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { createSuccessNotice } = useDispatch( noticesStore );

	if ( ! state ) {
		return <Spinner />;
	}

	const isUpdate = state.linked;

	const upload = () => {
		if ( busy ) {
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
				setError(
					err.message ||
						__( 'The upload failed. Try again.', 'pattern-builder' )
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

	return (
		<VStack spacing={ 3 }>
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
						disabled={ busy }
						onClick={ upload }
					>
						{ __( 'Upload to the cloud', 'pattern-builder' ) }
					</Button>
				</>
			) }

			{ state.linked && state.changed && (
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
						disabled={ busy }
						onClick={ upload }
					>
						{ __(
							'Update pattern on the cloud',
							'pattern-builder'
						) }
					</Button>
				</>
			) }

			{ state.linked && ! state.changed && (
				<Text variant="muted">
					{ __(
						'Up to date in your cloud library.',
						'pattern-builder'
					) }
					{ uploadedAgo ? ` ${ uploadedAgo }` : '' }
				</Text>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
		</VStack>
	);
}
