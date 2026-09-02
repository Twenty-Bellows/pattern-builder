import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalHStack as HStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { isListed, slugProblem, suggestSlug } from './collections';

const BASE = '/pattern-builder/v1/cloud';
const NEW = '__new__';

/**
 * Create a collection on the account. A free account's collections are
 * public from the moment they exist; the service decides and says so.
 *
 * @param {string} name        Collection name.
 * @param {string} slug        Collection slug — permanent, and the middle
 *                             segment of every pattern name in it.
 * @param {string} description Description (optional).
 * @param {string} visibility  'public' or 'private', or '' to leave it to the service.
 * @return {Promise<Object>} The created collection summary.
 */
export function createCollection(
	name,
	slug,
	description = '',
	visibility = ''
) {
	const data = { name, slug, description };
	if ( visibility ) {
		data.visibility = visibility;
	}
	return apiFetch( {
		path: `${ BASE }/library/collections`,
		method: 'POST',
		data,
	} );
}

/**
 * Which of the account's collections a pattern goes into, with "New
 * collection…" inline: pick it and a name field appears, and the
 * collection is created there and then and selected.
 *
 * @param {Object}   props             Component props.
 * @param {Array}    props.collections The account's collections.
 * @param {number}   props.value       The selected collection id.
 * @param {Function} props.onChange    Called with the selected collection id.
 * @param {Function} props.onCreated   Called with a collection just created, so the caller's list can grow.
 * @param {string}   props.label       The field label.
 * @param {boolean}  props.disabled    Whether the control is disabled.
 */
export function CollectionPicker( {
	collections,
	value,
	onChange,
	onCreated,
	label = __( 'Collection', 'pattern-builder' ),
	disabled = false,
} ) {
	const [ creating, setCreating ] = useState( false );
	const [ name, setName ] = useState( '' );
	// The slug follows the name until it is typed into, after which it is
	// the author's: it is permanent, so it is worth being able to say what
	// it will be rather than accepting whatever a title turns into.
	const [ slug, setSlug ] = useState( '' );
	const [ slugTouched, setSlugTouched ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const selected = ( collections || [] ).find(
		( item ) => item.id === value
	);

	const options = [
		...( collections || [] ).map( ( item ) => ( {
			value: String( item.id ),
			label: item.personal
				? __( 'Personal (private)', 'pattern-builder' )
				: sprintf(
						/* translators: 1: collection title, 2: visibility. */
						__( '%1$s (%2$s)', 'pattern-builder' ),
						item.title,
						isListed( item )
							? __( 'public', 'pattern-builder' )
							: __( 'private', 'pattern-builder' )
				  ),
		} ) ),
		{ value: NEW, label: __( 'New collection…', 'pattern-builder' ) },
	];

	const create = () => {
		if ( busy || ! name.trim() ) {
			return;
		}
		const wanted = slug.trim() || suggestSlug( name );
		const problem = slugProblem( wanted );
		if ( problem ) {
			setError( problem );
			return;
		}
		setBusy( true );
		setError( '' );
		createCollection( name.trim(), wanted )
			.then( ( created ) => {
				setBusy( false );
				setCreating( false );
				setName( '' );
				setSlug( '' );
				setSlugTouched( false );
				onCreated?.( created );
				onChange( created.id );
			} )
			.catch( ( err ) => {
				setBusy( false );
				setError(
					err.message ||
						__(
							'The collection could not be created.',
							'pattern-builder'
						)
				);
			} );
	};

	return (
		<VStack spacing={ 2 }>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ creating ? NEW : String( value || '' ) }
				options={ options }
				disabled={ disabled }
				onChange={ ( next ) => {
					if ( next === NEW ) {
						setCreating( true );
						return;
					}
					setCreating( false );
					onChange( Number( next ) );
				} }
			/>
			{ creating && (
				<HStack spacing={ 2 } alignment="bottom">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Name', 'pattern-builder' ) }
						value={ name }
						onChange={ ( next ) => {
							setName( next );
							if ( ! slugTouched ) {
								setSlug( suggestSlug( next ) );
							}
						} }
						disabled={ busy }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								create();
							}
						} }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Slug', 'pattern-builder' ) }
						value={ slug }
						onChange={ ( next ) => {
							setSlugTouched( true );
							setSlug( next );
						} }
						disabled={ busy }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								create();
							}
						} }
					/>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ busy }
						disabled={ busy || ! name.trim() }
						onClick={ create }
					>
						{ __( 'Create', 'pattern-builder' ) }
					</Button>
				</HStack>
			) }
			{ error && (
				<Text variant="muted" isDestructive>
					{ error }
				</Text>
			) }
			{ ! creating && selected && isListed( selected ) && (
				<Text variant="muted" size="12px">
					{ __(
						'This collection is public. The pattern will be listed once it passes the checks.',
						'pattern-builder'
					) }
				</Text>
			) }
		</VStack>
	);
}
