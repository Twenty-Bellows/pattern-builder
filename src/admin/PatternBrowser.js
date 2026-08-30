import { __, _x } from '@wordpress/i18n';
import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import {
	Button,
	Modal,
	SearchControl,
	SnackbarList,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
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
import {
	CloudBrowser,
	CLOUD_LIBRARY,
	CLOUD_DIRECTORY,
	CLOUD_GENERATE,
} from '../cloud/CloudBrowser';

const CLOUD_VIEWS = [ CLOUD_LIBRARY, CLOUD_DIRECTORY, CLOUD_GENERATE ];

const ALL = 'all';
const MINE = 'mine';
const UNCATEGORIZED = 'uncategorized';

/**
 * The category rail: All patterns, My patterns (user-created), every
 * category in use, and Uncategorized — each with a count, the way the Site
 * Editor's Patterns screen lays them out.
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
 * The browse screen: a category rail, a grid of uniform pattern cards, and
 * a details sidebar for the selected pattern.
 *
 * @param {Object}   props                Component props.
 * @param {Function} props.onEdit         Called with the pattern to open its editor.
 * @param {Object}   props.editorSettings Block editor settings for previews.
 */
export function PatternBrowser( { onEdit, editorSettings } ) {
	const [ patterns, setPatterns ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState( ALL );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ isCreateOpen, setIsCreateOpen ] = useState( false );

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

	const categories = useMemo( () => {
		const all = patterns || [];
		const labelFor = ( slug ) =>
			( registeredCategories || [] ).find( ( c ) => c.name === slug )
				?.label || slug;

		const counts = {};
		let uncategorized = 0;

		all.forEach( ( pattern ) => {
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
				count: all.length,
			},
			{
				slug: MINE,
				label: __( 'My patterns', 'pattern-builder' ),
				count: all.filter( ( p ) => p.source === 'user' ).length,
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

		rail.push(
			{
				slug: CLOUD_LIBRARY,
				label: __( 'My Cloud Library', 'pattern-builder' ),
				count: '',
			},
			{
				slug: CLOUD_DIRECTORY,
				label: __( 'Pattern Directory', 'pattern-builder' ),
				count: '',
			},
			{
				slug: CLOUD_GENERATE,
				label: __( 'Generate with AI', 'pattern-builder' ),
				count: '',
			}
		);

		return rail;
	}, [ patterns, registeredCategories ] );

	const filteredPatterns = useMemo( () => {
		if ( ! patterns ) {
			return [];
		}

		const term = search.trim().toLowerCase();

		return patterns.filter( ( pattern ) => {
			if ( category === MINE && pattern.source !== 'user' ) {
				return false;
			}

			if (
				category === UNCATEGORIZED &&
				( pattern.categories || [] ).length > 0
			) {
				return false;
			}

			if (
				category !== ALL &&
				category !== MINE &&
				category !== UNCATEGORIZED &&
				! ( pattern.categories || [] ).includes( category )
			) {
				return false;
			}

			if ( ! term ) {
				return true;
			}

			const haystack = [
				pattern.title,
				pattern.name,
				pattern.description,
				...( pattern.keywords || [] ),
				...( pattern.categories || [] ),
			]
				.join( ' ' )
				.toLowerCase();

			return haystack.includes( term );
		} );
	}, [ patterns, search, category ] );

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
		/*
		 * The provider gives the preview iframes real editor settings — theme
		 * styles, and the block-bindings attribute map the preview needs to
		 * render blocks with `__default` bindings.
		 */
		<BlockEditorProvider settings={ editorSettings }>
			<div className="pattern-builder-browser">
				<aside className="pattern-builder-browser__sidebar">
					<Heading
						level={ 1 }
						size={ 16 }
						className="pattern-builder-browser__title"
					>
						{ _x(
							'Pattern Builder',
							'UI String',
							'pattern-builder'
						) }
					</Heading>
					<CategoryRail
						categories={ categories }
						active={ category }
						onSelect={ ( slug ) => {
							setCategory( slug );
							setSelectedId( null );
						} }
					/>
				</aside>

				{ CLOUD_VIEWS.includes( category ) && (
					<main className="pattern-builder-browser__main">
						<CloudBrowser
							view={ category }
							onDownloaded={ refresh }
							onEditLocal={ onEdit }
						/>
					</main>
				) }

				{ ! CLOUD_VIEWS.includes( category ) && (
					<main className="pattern-builder-browser__main">
						<HStack
							alignment="left"
							spacing={ 4 }
							wrap
							className="pattern-builder-browser__toolbar"
						>
							<SearchControl
								__nextHasNoMarginBottom
								className="pattern-builder-browser__search"
								value={ search }
								onChange={ setSearch }
								label={ __(
									'Search patterns',
									'pattern-builder'
								) }
							/>
							<Button
								variant="primary"
								icon={ addTemplate }
								onClick={ () => setIsCreateOpen( true ) }
							>
								{ __( 'Create Pattern', 'pattern-builder' ) }
							</Button>
						</HStack>

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
									isSelected={ pattern.id === selectedId }
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
				) }

				{ ! CLOUD_VIEWS.includes( category ) && selectedPattern && (
					<aside className="pattern-builder-browser__details">
						<PatternDetailsPanel
							key={ selectedPattern.id }
							pattern={ selectedPattern }
							onEdit={ onEdit }
							onSaved={ refresh }
						/>
					</aside>
				) }

				{ isCreateOpen && (
					<Modal
						title={ __( 'Create Pattern', 'pattern-builder' ) }
						onRequestClose={ () => setIsCreateOpen( false ) }
						className="pattern-builder-browser__create-modal"
					>
						<PatternCreatePanel />
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
