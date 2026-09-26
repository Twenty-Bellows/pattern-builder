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
import { parse, serialize } from '@wordpress/blocks';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { addQueryArgs } from '@wordpress/url';

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
const LAST_COLLECTION_KEY = 'cloud-last-collection';

/**
 * A sentence naming blocks and the attributes each one lost, for a notice.
 *
 * @param {Array} blocks Entries from findOutdatedBlocks() with a `dropped` list.
 * @return {string} e.g. `cover (overlayColor), group (layout)`.
 */
function describeDropped( blocks ) {
	return blocks
		.map(
			( block ) =>
				`${ block.title } (${ ( block.dropped || [] ).join( ', ' ) })`
		)
		.join( ', ' );
}

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
 * One pattern's cloud standing: null while loading, then the /cloud/pattern-state payload —
 * `linked` when the pattern's `Cloud:` reference names one of the connected account's
 * patterns that still exists, with its `cloudId` and `collection`.
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
 * The Cloud panel's controls.
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
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ collectionId, setCollectionId ] = useState( 0 );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const { collections, reload: reloadCollections } = useCloudCollections(
		!! state?.connected
	);
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
	const invalid = useMemo( () => {
		if ( ! tree?.members?.length ) {
			return findInvalidBlocks( content );
		}
		return tree.members.flatMap( ( member ) =>
			findInvalidBlocks( member.content )
		);
	}, [ content, tree ] );
	const outdated = useMemo(
		() => findOutdatedBlocks( content ),
		[ content ]
	);
	// Two different things, and only one of them is fixed by rewriting. A block stored in an
	// old form is rewritten into the current one; an attribute the block type does not read
	// is sitting in the markup unread, and a rewrite is what would finally discard it.
	const oldForm = useMemo(
		() => outdated.filter( ( block ) => block.reason === 'old-form' ),
		[ outdated ]
	);
	const dropping = useMemo(
		() =>
			outdated.filter(
				( block ) => block.reason === 'dropped-attribute'
			),
		[ outdated ]
	);

	const postType = 'theme' === patternType ? 'pb_pattern' : 'wp_block';
	// In the editor this pattern's blocks are already the parsed, current ones, so saving is
	// the rewrite — and writing the entity from here would go behind the canvas's back.
	const isOpenInTheEditor = useSelect(
		( select ) => select( editorStore )?.getCurrentPostId() === patternId,
		[ patternId ]
	);
	const { receiveEntityRecords } = useDispatch( coreStore );

	if ( ! state ) {
		return <Spinner />;
	}

	const isUpdate = state.linked;

	const upload = () => {
		if ( busy || invalid.length ) {
			return;
		}
		setBusy( 'upload' );
		setError( '' );
		const data = { patternType, patternId };
		if ( ! isUpdate ) {
			data.collection = collectionId || 'personal';
		}
		apiFetch( {
			path: `${ BASE }/upload`,
			method: 'POST',
			data,
		} )
			.then( () => {
				setBusy( '' );
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
				setBusy( '' );
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
	/**
	 * Parse the saved markup and write it back out, which is what opening the pattern in the
	 * editor and saving it does: every block is read through its deprecations and serialized
	 * in the form its current `save()` produces.
	 *
	 * The answer is put straight into the store rather than the request being left to settle
	 * on its own, because a theme pattern is written through `format_block_markup()` and read
	 * back by running its file — so what the pattern now is, is whatever the server says it
	 * is, and the notice has to be recomputed from that.
	 */
	const rewrite = async () => {
		if ( busy ) {
			return;
		}
		setBusy( 'rewrite' );
		setError( '' );

		try {
			const saved = await apiFetch( {
				path:
					'theme' === patternType
						? `/pattern-builder/v1/patterns/${ patternId }`
						: `/wp/v2/blocks/${ patternId }`,
				method: 'PUT',
				data: { content: serialize( parse( content ) ) },
			} );

			receiveEntityRecords(
				'postType',
				postType,
				saved,
				undefined,
				true
			);
			setBusy( '' );
			createSuccessNotice(
				__( 'Pattern rewritten.', 'pattern-builder' ),
				{ type: 'snackbar' }
			);
			onRefresh();
		} catch ( err ) {
			setBusy( '' );
			setError(
				err?.message ||
					__(
						'The pattern could not be rewritten.',
						'pattern-builder'
					)
			);
		}
	};

	const deleteFromCloud = () => {
		if ( busy ) {
			return;
		}
		const confirmed =
			// eslint-disable-next-line no-alert
			window.confirm(
				__(
					'Delete this pattern from your cloud library? It stays on this site, and sites that downloaded it keep their copies.',
					'pattern-builder'
				)
			);
		if ( ! confirmed ) {
			return;
		}
		setBusy( 'delete' );
		setError( '' );
		apiFetch( {
			path: addQueryArgs( `${ BASE }/library/${ state.cloudId }`, {
				patternType,
				patternId,
			} ),
			method: 'DELETE',
		} )
			.then( () => {
				setBusy( '' );
				createSuccessNotice(
					__(
						'Pattern deleted from your cloud library.',
						'pattern-builder'
					),
					{ type: 'snackbar' }
				);
				onRefresh();
			} )
			.catch( ( err ) => {
				setBusy( '' );
				setError(
					err.message ||
						__( 'Could not delete the pattern.', 'pattern-builder' )
				);
			} );
	};

	const blockedByInvalidBlocks = invalid.length > 0;

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
					{ asks && (
						<CollectionPicker
							collections={ collections }
							value={ collectionId }
							onChange={ setCollectionId }
							onCreated={ reloadCollections }
							disabled={ !! busy }
						/>
					) }
					<Button
						variant="primary"
						icon={ cloudUpload }
						isBusy={ busy === 'upload' }
						disabled={ !! busy || blockedByInvalidBlocks }
						onClick={ upload }
					>
						{ __( 'Upload to the cloud', 'pattern-builder' ) }
					</Button>
				</>
			) }

			{ state.linked && (
				<>
					{ state.collection?.title && (
						<Text variant="muted">
							{ sprintf(
								/* translators: %s: collection title. */
								__(
									'In your cloud library, in %s.',
									'pattern-builder'
								),
								state.collection.title
							) }
						</Text>
					) }
					<Button
						variant="primary"
						icon={ cloudUpload }
						isBusy={ busy === 'upload' }
						disabled={ !! busy || blockedByInvalidBlocks }
						onClick={ upload }
					>
						{ __(
							'Update pattern on the cloud',
							'pattern-builder'
						) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						isBusy={ busy === 'delete' }
						disabled={ !! busy }
						onClick={ deleteFromCloud }
					>
						{ __( 'Delete from cloud', 'pattern-builder' ) }
					</Button>
				</>
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

			{ ! blockedByInvalidBlocks && oldForm.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<VStack spacing={ 2 }>
						<span>
							{ sprintf(
								/* translators: %s: comma separated block names, e.g. "heading (2), list". */
								__(
									'Stored in an older form: %s. These upload and render, but WordPress reads them as a previous version of the block, so styling set on them may not be applied.',
									'pattern-builder'
								),
								describeBlocks( oldForm )
							) }
						</span>
						{ isOpenInTheEditor ? (
							<span>
								{ __(
									'Save the pattern to rewrite them.',
									'pattern-builder'
								) }
							</span>
						) : (
							<Button
								variant="secondary"
								isBusy={ busy === 'rewrite' }
								disabled={ !! busy }
								onClick={ rewrite }
							>
								{ __( 'Rewrite pattern', 'pattern-builder' ) }
							</Button>
						) }
					</VStack>
				</Notice>
			) }

			{ ! blockedByInvalidBlocks && dropping.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: block names with the attributes each one lost, e.g. "cover (overlayColor)". */
						__(
							'Settings no block type reads are stored on: %s. They are in the markup but nothing applies them, and saving the pattern — here or in the editor — is what would discard them. Open it in the editor and set what you meant on the block itself.',
							'pattern-builder'
						),
						describeDropped( dropping )
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
