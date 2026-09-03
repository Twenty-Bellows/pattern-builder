import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
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
	findOutdatedBlocks,
	describeBlocks,
} from '../utils/blockValidity';
import {
	getLocalStorageValue,
	setLocalStorageValue,
} from '../utils/localStorage';
import { CollectionPicker } from '../cloud/CollectionPicker';
import { referencesOf } from '../utils/patternTree';
import {
	pickDefaultCollection,
	shouldAskForCollection,
} from '../cloud/collections';

const BASE = '/pattern-builder/v1/cloud';

// Which collection the last upload went into, so the next one offers it.
const LAST_COLLECTION_KEY = 'cloud-last-collection';

/**
 * The account's collections, fetched once the panel knows it is connected.
 *
 * @param {boolean} connected Whether there is a connection.
 * @return {Object} { collections, reload }
 */
export function useCloudCollections( connected ) {
	const [ collections, setCollections ] = useState( null );

	const reload = useCallback( () => {
		if ( ! connected ) {
			setCollections( null );
			return;
		}
		apiFetch( { path: `${ BASE }/library/collections` } )
			.then( ( data ) =>
				setCollections( Array.isArray( data ) ? data : [] )
			)
			.catch( () => setCollections( [] ) );
	}, [ connected ] );

	useEffect( reload, [ reload ] );

	return { collections, reload };
}

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
	const [ collectionId, setCollectionId ] = useState( 0 );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const { collections, reload: reloadCollections } = useCloudCollections(
		!! state?.connected
	);

	// With only Personal, nothing is asked; with more, the picker defaults
	// to the collection used last.
	const asks = shouldAskForCollection( collections );
	useEffect( () => {
		if ( ! collections || collectionId ) {
			return;
		}
		const chosen = pickDefaultCollection(
			collections,
			Number( getLocalStorageValue( LAST_COLLECTION_KEY, 0 ) )
		);
		if ( chosen ) {
			setCollectionId( chosen.id );
		}
	}, [ collections, collectionId ] );

	/*
	 * A pattern that references others brings them with it (D38), so the
	 * panel says so before the upload and checks every member, not just
	 * this one. The cheap local read decides whether to ask at all: a
	 * pattern that references nothing costs no request.
	 */
	const [ tree, setTree ] = useState( null );
	const hasReferences = useMemo(
		() => referencesOf( content ).length > 0,
		[ content ]
	);

	useEffect( () => {
		if ( ! hasReferences || ! state?.connected ) {
			setTree( null );
			return;
		}

		let live = true;
		apiFetch( {
			path: addQueryArgs( `${ BASE }/pattern-tree`, {
				patternType,
				patternId,
			} ),
		} )
			.then( ( answer ) => live && setTree( answer ) )
			.catch( () => live && setTree( null ) );

		return () => {
			live = false;
		};
	}, [ hasReferences, state?.connected, patternType, patternId ] );

	// The saved markup is what the server uploads, so that is what gets
	// checked — not whatever is unsaved in the canvas. Every member of the
	// tree is checked, because every member is uploaded.
	const invalid = useMemo( () => {
		if ( ! tree?.members?.length ) {
			return findInvalidBlocks( content );
		}
		return tree.members.flatMap( ( member ) =>
			findInvalidBlocks( member.content )
		);
	}, [ content, tree ] );

	// Not a fault the service will refuse, and not one an editor complains
	// about either — which is exactly why it is worth saying here.
	const outdated = useMemo(
		() => findOutdatedBlocks( content ),
		[ content ]
	);

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
		const data = { patternType, patternId };
		// An update keeps its collection; a first upload names one.
		if ( ! isUpdate ) {
			data.collection = collectionId || 'personal';
		}
		apiFetch( {
			path: `${ BASE }/upload`,
			method: 'POST',
			data,
		} )
			.then( () => {
				setBusy( false );
				if ( ! isUpdate && collectionId ) {
					setLocalStorageValue( LAST_COLLECTION_KEY, collectionId );
				}
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
					{ asks && (
						<CollectionPicker
							collections={ collections }
							value={ collectionId }
							onChange={ setCollectionId }
							onCreated={ reloadCollections }
							disabled={ busy }
						/>
					) }
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

			{ state.linked && state.owned && state.collection?.title && (
				<Text variant="muted" size="12px">
					{ sprintf(
						/* translators: %s: collection title. */
						__( 'In %s.', 'pattern-builder' ),
						state.collection.title
					) }
				</Text>
			) }

			{ tree?.problem && (
				<Notice status="warning" isDismissible={ false }>
					{ tree.problem }
				</Notice>
			) }

			{ ! tree?.problem && tree?.members?.length > 1 && (
				<Text variant="muted" size="12px">
					{ sprintf(
						/* translators: 1: number of patterns, 2: comma-separated pattern titles. */
						_n(
							'Uploads %1$d pattern: %2$s.',
							'Uploads %1$d patterns: %2$s.',
							tree.members.length,
							'pattern-builder'
						),
						tree.members.length,
						tree.members
							.map( ( member ) => member.title )
							.reverse()
							.join( ', ' )
					) }{ ' ' }
					{ __(
						'A pattern that uses others takes them along, and updates the ones already in the collection — which changes every pattern there that uses them.',
						'pattern-builder'
					) }
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
						describeBlocks( invalid )
					) }
				</Notice>
			) }

			{ ! blockedByInvalidBlocks && outdated.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: comma separated block names, e.g. "heading (2), list". */
						__(
							'Stored in an older form: %s. These upload and render, but WordPress reads them as a previous version of the block, so styling set on them may not be applied. Open the pattern in the editor and save it to rewrite them.',
							'pattern-builder'
						),
						describeBlocks( outdated )
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
