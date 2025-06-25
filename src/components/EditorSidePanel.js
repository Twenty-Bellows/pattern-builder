/**
 * WordPress dependencies
 */
import { __, _x } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { PanelBody } from '@wordpress/components';
import { Navigator, Icon } from '@wordpress/components';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalDivider as Divider,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHeading as Heading,
} from '@wordpress/components';
import {
	widget,
	addTemplate,
	category as categoryIcon,
	download,
	chevronLeft,
	chevronRight,
	addCard,
	cog,
} from '@wordpress/icons';

import { fetchAllPatterns } from '../utils/resolvers';
import { PatternCreatePanel } from './PatternCreatePanel';
import { useEffect } from 'react';
import { useMemo } from 'react';
import { PatternBrowserPanel } from './PatternBrowserPanel';
import { PatternBuilderConfiguration } from './PatternBuilderConfiguration';
import { patternBuilderAppIcon } from '../assets/icons';

export const EditorSidePanel = () => {
	const [ allPatterns, setAllPatterns ] = useState( [] );

	useEffect( () => {
		fetchAllPatterns()
			.then( ( patterns ) => {
				setAllPatterns( patterns );
			} )
			.catch( ( error ) => {
				console.error( 'Error fetching patterns:', error );
			} );
	}, [] );

	const patternCategories = useMemo( () => {
		const categories = Object.values(
			allPatterns.reduce( ( acc, pattern ) => {
				if ( pattern.inserter === false ) {
					if ( ! acc[ 'hidden' ] ) {
						acc[ 'hidden' ] = {
							label: __( 'Hidden', 'pattern-builder' ),
							value: 'hidden',
						};
					}
				}
				if (
					pattern.inserter &&
					( ! pattern.categories || pattern.categories.length === 0 )
				) {
					if ( ! acc[ 'uncategorized' ] ) {
						acc[ 'uncategorized' ] = {
							label: __( 'Uncategorized', 'pattern-builder' ),
							value: 'uncategorized',
						};
					}
				}
				pattern.categories.forEach( ( category ) => {
					if ( ! acc[ category ] ) {
						acc[ category ] = {
							label:
								category.charAt( 0 ).toUpperCase() +
								category.slice( 1 ),
							value: category,
						};
					}
				} );
				return acc;
			}, {} )
		);

		return categories;
	}, [ allPatterns ] );

	return (
		<>
			<PluginSidebarMoreMenuItem target="pattern-builder-sidebar">
				{ _x( 'Pattern Builder', 'UI String', 'pattern-builder' ) }
			</PluginSidebarMoreMenuItem>

			<PluginSidebar
				className="pattern-builder__editor-sidebar"
				name="pattern-builder-sidebar"
				icon={ patternBuilderAppIcon }
				title={ _x(
					'Pattern Builder',
					'UI String',
					'pattern-builder'
				) }
			>
				<Navigator initialPath="/">
					<Navigator.Screen path="/">
						<PanelBody>
							<VStack spacing={ 1 }>
								<Navigator.Button
									icon={ addTemplate }
									path="/create"
								>
									<Text
										style={ { flex: 1, textAlign: 'left' } }
									>
										{ __(
											'Create Pattern',
											'pattern-builder'
										) }
									</Text>
									<Icon icon={ chevronRight } />
								</Navigator.Button>
								<Divider />
								<Text style={ { marginBottom: '10px' } }>
									{ __(
										'Pattern Categories',
										'pattern-builder'
									) }
								</Text>
								{ patternCategories.map( ( category ) => (
									<Navigator.Button
										key={ category.value }
										icon={ categoryIcon }
										path={ `/browse/${ category.value }` }
									>
										<Text
											style={ {
												flex: 1,
												textAlign: 'left',
												whiteSpace: 'nowrap',
												overflow: 'hidden',
											} }
										>
											{ category.label }
										</Text>
										<Icon icon={ chevronRight } />
									</Navigator.Button>
								) ) }
								<Divider/>
								<Navigator.Button
									icon={ cog }
									path="/configuration"
								>
									<Text
										style={ { flex: 1, textAlign: 'left' } }
									>
										{ __(
											'Configuration',
											'pattern-builder'
										) }
									</Text>
									<Icon icon={ chevronRight } />
								</Navigator.Button>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/create">
						<PanelBody>
							<HStack spacing={ 2 } alignment="left">
								<Navigator.BackButton
									icon={ chevronLeft }
									label={ __( 'Back', 'pattern-builder' ) }
								/>
								<Heading
									level={ 2 }
									size={ 13 }
									style={ { margin: 0 } }
								>
									{ __(
										'Create Pattern',
										'pattern-builder'
									) }
								</Heading>
							</HStack>
							<PatternCreatePanel />
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/browse/:category">
						<PanelBody>
							<VStack spacing={ 4 }>
								<HStack spacing={ 2 } alignment="left">
									<Navigator.BackButton
										icon={ chevronLeft }
										label={ __(
											'Back',
											'pattern-builder'
										) }
									/>
									<Heading
										level={ 2 }
										size={ 13 }
										style={ { margin: 0 } }
									>
										{ __( 'Browse', 'pattern-builder' ) }
									</Heading>
								</HStack>
								<PatternBrowserPanel
									allPatterns={ allPatterns }
								/>
							</VStack>
						</PanelBody>
					</Navigator.Screen>
					<Navigator.Screen path="/configuration">
						<PanelBody>
							<VStack spacing={ 4 }>
								<HStack spacing={ 2 } alignment="left">
									<Navigator.BackButton
										icon={ chevronLeft }
										label={ __(
											'Back',
											'pattern-builder'
										) }
									/>
									<Heading
										level={ 2 }
										size={ 13 }
										style={ { margin: 0 } }
									>
										{ __(
											'Configuration',
											'pattern-builder'
										) }
									</Heading>
								</HStack>
								<PatternBuilderConfiguration />
							</VStack>
						</PanelBody>
					</Navigator.Screen>
				</Navigator>
			</PluginSidebar>
		</>
	);
};
