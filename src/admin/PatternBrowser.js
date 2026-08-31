import { __, _x } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import {
	Button,
	Modal,
	SearchControl,
	SnackbarList,
	Spinner,
} from '@wordpress/components';
import { addTemplate } from '@wordpress/icons';
import { BlockEditorProvider } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as noticesStore } from '@wordpress/notices';

import { fetchAllPatterns } from '../utils/resolvers';
import { PatternCard } from '../components/PatternCard';
import { PatternDetailsPanel } from '../components/PatternDetailsPanel';
import { PatternCreatePanel } from '../components/PatternCreatePanel';
import { PatternBuilderLogo } from '../assets/icons';
import {
	CloudBrowser,
	CLOUD_LIBRARY,
	CLOUD_DIRECTORY,
} from '../cloud/CloudBrowser';

const ALL = 'all';
const UNCATEGORIZED = 'uncategorized';

/**
 * The four collections a pattern can come from. User and Theme are local;
 * Uploaded and Community are served by the cloud browser.
 */
const USER = 'user';
const THEME = 'theme';
const UPLOADED = 'uploaded';
const COMMUNITY = 'community';

const COLLECTIONS = [
	{ key: USER, label: __( 'User', 'pattern-builder' ) },
	{ key: THEME, label: __( 'Theme', 'pattern-builder' ) },
	{ key: UPLOADED, label: __( 'Uploaded', 'pattern-builder' ) },
	{ key: COMMUNITY, label: __( 'Community', 'pattern-builder' ) },
];

const CLOUD_COLLECTIONS = [ UPLOADED, COMMUNITY ];

/**
 * The collection tabs: which library the grid is showing.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.active   Active collection key.
 * @param {Function} props.onSelect Called with a collection key.
 */
function CollectionTabs( { active, onSelect } ) {
	return (
		<div
			className="pattern-builder-browser__tabs"
			role="tablist"
			aria-label={ __( 'Pattern collections', 'pattern-builder' ) }
		>
			{ COLLECTIONS.map( ( item ) => (
				<button
					key={ item.key }
					type="button"
					role="tab"
					aria-selected={ item.key === active }
					className={
						'pattern-builder-browser__tab' +
						( item.key === active ? ' is-active' : '' )
					}
					onClick={ () => onSelect( item.key ) }
				>
					{ item.label }
				</button>
			) ) }
		</div>
	);
}

/**
 * The category rail: filters within the active collection — pattern
 * categories for the local collections, cloud collections for the others.
 *
 * @param {Object}   props            Component props.
 * @param {Array}    props.categories The category descriptors.
 * @param {string}   props.active     The active category slug.
 * @param {Function} props.onSelect   Called with a category slug.
 */
function CategoryRail( { categories, active, onSelect } ) {
	return (
		<ul
			className="pattern-builder-browser__categories"
			aria-label={ __( 'Pattern categories', 'pattern-builder' ) }
		>
			{ categories.map( ( category ) => (
				<li key={ category.slug }>
					<button
						type="button"
						className={
							'pattern-builder-browser__category' +
							( category.slug === active ? ' is-active' : '' )
						}
						onClick={ () => onSelect( category.slug ) }
					>
						<span className="pattern-builder-browser__category-label">
							{ category.label }
						</span>
						<span className="pattern-builder-browser__category-count">
							{ category.count }
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}

/**
 * The browse screen: a header with the four collection tabs, a category
 * rail scoped to the active collection, a grid, and a details sidebar that
 * is always present.
 *
 * @param {Object}   props                Component props.
 * @param {Function} props.onEdit         Called with the pattern to open its editor.
 * @param {Object}   props.editorSettings Block editor settings for previews.
 */
export function PatternBrowser( { onEdit, editorSettings } ) {
	const [ patterns, setPatterns ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ collection, setCollection ] = useState( USER );
	const [ category, setCategory ] = useState( ALL );
	const [ cloudCollections, setCloudCollections ] = useState( [] );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ isCreateOpen, setIsCreateOpen ] = useState( false );

	const isCloud = CLOUD_COLLECTIONS.includes( collection );

	const refresh = useCallback( () => {
		fetchAllPatterns()
			.then( setPatterns )
			.catch( () => setPatterns( [] ) );
	}, [] );

	useEffect( refresh, [ refresh ] );

	// Labels for registered pattern categories; raw slugs otherwise.
	const registeredCategories = useSelect(
		( select ) => select( coreStore ).getBlockPatternCategories(),
		[]
	);

	const snackbarNotices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	const selectCollection = ( key ) => {
		setCollection( key );
		setCategory( ALL );
		setSearch( '' );
		setSelectedId( null );
		setCloudCollections( [] );
	};

	// Patterns in the active local collection, before the category filter.
	const collectionPatterns = useMemo(
		() =>
			( patterns || [] ).filter( ( pattern ) =>
				collection === USER
					? pattern.source === 'user'
					: pattern.source === 'theme'
			),
		[ patterns, collection ]
	);

	const categories = useMemo( () => {
		if ( isCloud ) {
			return [
				{
					slug: ALL,
					label: __( 'All patterns', 'pattern-builder' ),
					count: '',
				},
				...cloudCollections.map( ( item ) => ( {
					slug: item.id ? String( item.id ) : UNCATEGORIZED,
					label: item.name,
					count: item.count ?? '',
				} ) ),
			];
		}

		const labelFor = ( slug ) =>
			( registeredCategories || [] ).find( ( c ) => c.name === slug )
				?.label || slug;

		const counts = {};
		let uncategorized = 0;

		collectionPatterns.forEach( ( pattern ) => {
			const slugs = pattern.categories || [];
			if ( slugs.length === 0 ) {
				uncategorized++;
			}
			slugs.forEach( ( slug ) => {
				counts[ slug ] = ( counts[ slug ] || 0 ) + 1;
			} );
		} );

		const rail = [
			{
				slug: ALL,
				label: __( 'All patterns', 'pattern-builder' ),
				count: collectionPatterns.length,
			},
			...Object.keys( counts )
				.sort( ( a, b ) =>
					labelFor( a ).localeCompare( labelFor( b ) )
				)
				.map( ( slug ) => ( {
					slug,
					label: labelFor( slug ),
					count: counts[ slug ],
				} ) ),
		];

		if ( uncategorized > 0 ) {
			rail.push( {
				slug: UNCATEGORIZED,
				label: __( 'Uncategorized', 'pattern-builder' ),
				count: uncategorized,
			} );
		}

		return rail;
	}, [
		isCloud,
		cloudCollections,
		collectionPatterns,
		registeredCategories,
	] );

	const filteredPatterns = useMemo( () => {
		const term = search.trim().toLowerCase();

		return collectionPatterns.filter( ( pattern ) => {
			const slugs = pattern.categories || [];

			if ( category === UNCATEGORIZED && slugs.length > 0 ) {
				return false;
			}

			if (
				category !== ALL &&
				category !== UNCATEGORIZED &&
				! slugs.includes( category )
			) {
				return false;
			}

			if ( ! term ) {
				return true;
			}

			return [
				pattern.title,
				pattern.name,
				pattern.description,
				...( pattern.keywords || [] ),
				...slugs,
			]
				.join( ' ' )
				.toLowerCase()
				.includes( term );
		} );
	}, [ collectionPatterns, search, category ] );

	const selectedPattern = useMemo(
		() =>
			( patterns || [] ).find(
				( pattern ) => pattern.id === selectedId
			) || null,
		[ patterns, selectedId ]
	);

	if ( null === patterns ) {
		return (
			<div className="pattern-builder-browser__loading">
				<Spinner />
			</div>
		);
	}

	return (
		// Real editor settings: previews need theme styles and the
		// block-bindings map to render `__default` bindings.
		<BlockEditorProvider settings={ editorSettings }>
			<div className="pattern-builder-browser">
				<header className="pattern-builder-browser__header">
					<div className="pattern-builder-browser__brand">
						<PatternBuilderLogo size={ 28 } />
						<span className="pattern-builder-browser__brand-name">
							{ _x(
								'Pattern Builder',
								'UI String',
								'pattern-builder'
							) }
						</span>
					</div>

					<CollectionTabs
						active={ collection }
						onSelect={ selectCollection }
					/>

					<div className="pattern-builder-browser__header-actions">
						<SearchControl
							__nextHasNoMarginBottom
							className="pattern-builder-browser__search"
							value={ search }
							onChange={ setSearch }
							label={ __( 'Search patterns', 'pattern-builder' ) }
						/>
						<Button
							variant="primary"
							icon={ addTemplate }
							onClick={ () => setIsCreateOpen( true ) }
						>
							{ __( 'Create Pattern', 'pattern-builder' ) }
						</Button>
					</div>
				</header>

				<div className="pattern-builder-browser__body">
					<aside className="pattern-builder-browser__sidebar">
						<CategoryRail
							categories={ categories }
							active={ category }
							onSelect={ ( slug ) => {
								setCategory( slug );
								setSelectedId( null );
							} }
						/>
					</aside>

					{ isCloud && (
						<CloudBrowser
							view={
								collection === UPLOADED
									? CLOUD_LIBRARY
									: CLOUD_DIRECTORY
							}
							search={ search }
							collection={ category === ALL ? '' : category }
							onCollections={ setCloudCollections }
							onDownloaded={ refresh }
							onEditLocal={ onEdit }
						/>
					) }

					{ ! isCloud && (
						<>
							<main className="pattern-builder-browser__main">
								{ filteredPatterns.length === 0 && (
									<p>
										{ __(
											'No patterns found.',
											'pattern-builder'
										) }
									</p>
								) }

								<div className="pattern-builder-browser__grid">
									{ filteredPatterns.map( ( pattern ) => (
										<PatternCard
											key={ pattern.id || pattern.name }
											pattern={ pattern }
											isSelected={
												pattern.id === selectedId
											}
											onSelect={ ( selected ) =>
												setSelectedId(
													selected.id === selectedId
														? null
														: selected.id
												)
											}
										/>
									) ) }
								</div>
							</main>

							<aside className="pattern-builder-browser__details">
								<PatternDetailsPanel
									key={ selectedPattern?.id || 'none' }
									pattern={ selectedPattern }
									onEdit={ onEdit }
									onSaved={ refresh }
								/>
							</aside>
						</>
					) }
				</div>

				{ isCreateOpen && (
					<Modal
						title={ __( 'Create Pattern', 'pattern-builder' ) }
						onRequestClose={ () => setIsCreateOpen( false ) }
						className="pattern-builder-browser__create-modal"
					>
						<PatternCreatePanel
							onCreated={ () => {
								setIsCreateOpen( false );
								refresh();
							} }
						/>
					</Modal>
				) }

				<SnackbarList
					className="pattern-builder-browser__snackbars"
					notices={ snackbarNotices }
					onRemove={ removeNotice }
				/>
			</div>
		</BlockEditorProvider>
	);
}
