import { __, _x } from '@wordpress/i18n';
import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Button,
	Modal,
	SearchControl,
	Spinner,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { addTemplate } from '@wordpress/icons';
import { BlockEditorProvider } from '@wordpress/block-editor';

import { fetchAllPatterns } from '../utils/resolvers';
import { PatternPreview } from '../components/PatternPreview';
import { PatternCreatePanel } from '../components/PatternCreatePanel';

/**
 * The browse screen: every pattern the site has, theme and user, with
 * search, source filtering, and a create flow.
 *
 * @param {Object}   props                Component props.
 * @param {Function} props.onEdit         Called with the pattern to open.
 * @param {Object}   props.editorSettings Block editor settings for previews.
 */
export function PatternBrowser( { onEdit, editorSettings } ) {
	const [ patterns, setPatterns ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ sourceFilter, setSourceFilter ] = useState( 'all' );
	const [ isCreateOpen, setIsCreateOpen ] = useState( false );

	useEffect( () => {
		fetchAllPatterns()
			.then( setPatterns )
			.catch( () => setPatterns( [] ) );
	}, [] );

	const filteredPatterns = useMemo( () => {
		if ( ! patterns ) {
			return [];
		}

		const term = search.trim().toLowerCase();

		return patterns.filter( ( pattern ) => {
			if ( sourceFilter !== 'all' && pattern.source !== sourceFilter ) {
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
	}, [ patterns, search, sourceFilter ] );

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
				<VStack spacing={ 4 }>
					<HStack alignment="edge">
						<Heading level={ 1 } size={ 20 }>
							{ _x(
								'Pattern Builder',
								'UI String',
								'pattern-builder'
							) }
						</Heading>
						<Button
							variant="primary"
							icon={ addTemplate }
							onClick={ () => setIsCreateOpen( true ) }
						>
							{ __( 'Create Pattern', 'pattern-builder' ) }
						</Button>
					</HStack>

					<HStack alignment="left" spacing={ 4 } wrap>
						<SearchControl
							__nextHasNoMarginBottom
							className="pattern-builder-browser__search"
							value={ search }
							onChange={ setSearch }
							label={ __( 'Search patterns', 'pattern-builder' ) }
						/>
						<ToggleGroupControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={ __( 'Pattern source', 'pattern-builder' ) }
							value={ sourceFilter }
							onChange={ setSourceFilter }
						>
							<ToggleGroupControlOption
								value="all"
								label={ __( 'All', 'pattern-builder' ) }
							/>
							<ToggleGroupControlOption
								value="theme"
								label={ __( 'Theme', 'pattern-builder' ) }
							/>
							<ToggleGroupControlOption
								value="user"
								label={ __( 'User', 'pattern-builder' ) }
							/>
						</ToggleGroupControl>
					</HStack>

					{ filteredPatterns.length === 0 && (
						<p>{ __( 'No patterns found.', 'pattern-builder' ) }</p>
					) }

					<div className="pattern-builder-browser__grid">
						{ filteredPatterns.map( ( pattern ) => (
							<PatternPreview
								key={ pattern.id || pattern.name }
								pattern={ pattern }
								onClick={ onEdit }
								onEditClick={ onEdit }
							/>
						) ) }
					</div>
				</VStack>

				{ isCreateOpen && (
					<Modal
						title={ __( 'Create Pattern', 'pattern-builder' ) }
						onRequestClose={ () => setIsCreateOpen( false ) }
						className="pattern-builder-browser__create-modal"
					>
						<PatternCreatePanel />
					</Modal>
				) }
			</div>
		</BlockEditorProvider>
	);
}
