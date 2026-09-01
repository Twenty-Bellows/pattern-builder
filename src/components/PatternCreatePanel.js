/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import {
	CheckboxControl,
	TextControl,
	TextareaControl,
	Button,
	Icon,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import { navigateToPattern } from '../utils/patternNavigation';
import {
	PATTERN_KINDS,
	DESIGN,
	STORAGE_FIELD,
	POST_TYPES_FIELD,
	getPatternKind,
	getInitialValues,
	kindHasField,
	canCreate,
	buildCreateRequest,
} from './patternKinds';
import './PatternCreatePanel.scss';

/**
 * Where the pattern lives: a file in the theme, or the database.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.value    The current source.
 * @param {Function} props.onChange Called with the chosen source.
 */
function StorageField( { value, onChange } ) {
	return (
		<VStack spacing={ 2 }>
			<ToggleGroupControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __(
					'Where should this pattern be stored?',
					'pattern-builder'
				) }
				value={ value }
				onChange={ onChange }
			>
				<ToggleGroupControlOption
					value="theme"
					label={ __( 'Theme', 'pattern-builder' ) }
				/>
				<ToggleGroupControlOption
					value="user"
					label={ __( 'User', 'pattern-builder' ) }
				/>
			</ToggleGroupControl>
			<Text variant="muted">
				{ 'theme' === value
					? __(
							'Theme Patterns are stored as files in your theme. They are tied to the current theme and can be exported with it to be used in other environments.',
							'pattern-builder'
					  )
					: __(
							'User Patterns are stored in the database. They are not tied to a theme, but they only exist on this site.',
							'pattern-builder'
					  ) }
			</Text>
		</VStack>
	);
}

/**
 * Which post types are offered the pattern when new content is created.
 *
 * @param {Object}   props          Component props.
 * @param {string[]} props.value    The chosen post type slugs.
 * @param {Function} props.onChange Called with the chosen slugs.
 */
function PostTypesField( { value, onChange } ) {
	const postTypes = useSelect( ( select ) => {
		const types = select( coreStore ).getPostTypes( { per_page: -1 } );

		return ( types || [] )
			.filter( ( type ) => type.viewable && 'attachment' !== type.slug )
			.map( ( type ) => ( {
				slug: type.slug,
				label: type.labels?.singular_name || type.name || type.slug,
			} ) );
	}, [] );

	const toggle = ( slug, checked ) => {
		onChange(
			checked
				? [ ...value, slug ]
				: value.filter( ( item ) => item !== slug )
		);
	};

	return (
		<VStack spacing={ 2 }>
			<p className="pattern-builder-create__field-label">
				{ __(
					'Which post types should offer this pattern?',
					'pattern-builder'
				) }
			</p>
			<div
				className="pattern-builder-create__checkboxes"
				role="group"
				aria-label={ __( 'Post types', 'pattern-builder' ) }
			>
				{ postTypes.map( ( type ) => (
					<CheckboxControl
						key={ type.slug }
						__nextHasNoMarginBottom
						label={ type.label }
						checked={ value.includes( type.slug ) }
						onChange={ ( checked ) => toggle( type.slug, checked ) }
					/>
				) ) }
			</div>
			<Text variant="muted">
				{ __(
					'Starter Patterns are stored in your theme — the contexts they are offered in are recorded in the pattern file.',
					'pattern-builder'
				) }
			</Text>
		</VStack>
	);
}

/**
 * Creates a pattern from one of a few kinds.
 *
 * The kinds sit on the left; picking one describes what that kind of pattern
 * is for and asks only for what it leaves open — every kind takes a name and
 * a description, and each fixes the rest of the metadata itself.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onCreated Called with the created pattern.
 * @param {string}   props.layout    'columns' (the modal) or 'stacked' (the editor sidebar).
 */
export const PatternCreatePanel = ( { onCreated, layout = 'columns' } ) => {
	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		const { getSettings } = select( blockEditorStore );
		return {
			onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
		};
	}, [] );

	const [ kindKey, setKindKey ] = useState( DESIGN );
	const [ values, setValues ] = useState( () =>
		getInitialValues( getPatternKind( DESIGN ) )
	);
	const [ error, setError ] = useState( '' );
	const [ isCreating, setIsCreating ] = useState( false );

	const kind = getPatternKind( kindKey );

	const setValue = ( edit ) =>
		setValues( ( current ) => ( { ...current, ...edit } ) );

	const selectKind = ( key ) => {
		setKindKey( key );
		setError( '' );
		// What the user has typed survives the switch; what the previous
		// kind decided does not.
		setValues( ( current ) => ( {
			...getInitialValues( getPatternKind( key ) ),
			title: current.title,
			description: current.description,
		} ) );
	};

	const create = () => {
		setIsCreating( true );
		setError( '' );

		apiFetch( buildCreateRequest( kind, values ) )
			.then( ( pattern ) => {
				const created = {
					id: pattern.id,
					name: pattern.name,
					source: pattern.source || 'user',
				};

				if ( onCreated ) {
					onCreated( created );
				}

				navigateToPattern( created, onNavigateToEntityRecord );
			} )
			.catch( ( createError ) => {
				setIsCreating( false );
				setError(
					createError.message ||
						__(
							'The pattern could not be created.',
							'pattern-builder'
						)
				);
			} );
	};

	return (
		<div
			className={
				'pattern-builder-create' +
				( 'stacked' === layout ? ' is-stacked' : '' )
			}
		>
			<div
				className="pattern-builder-create__kinds"
				role="group"
				aria-label={ __( 'Kind of pattern', 'pattern-builder' ) }
			>
				{ PATTERN_KINDS.map( ( item ) => (
					<button
						key={ item.key }
						type="button"
						aria-pressed={ item.key === kindKey }
						className={
							'pattern-builder-create__kind' +
							( item.key === kindKey ? ' is-selected' : '' )
						}
						onClick={ () => selectKind( item.key ) }
					>
						<Icon icon={ item.icon } size={ 24 } />
						<span className="pattern-builder-create__kind-text">
							<span className="pattern-builder-create__kind-label">
								{ item.label }
							</span>
							<span className="pattern-builder-create__kind-summary">
								{ item.summary }
							</span>
						</span>
					</button>
				) ) }
			</div>

			<div className="pattern-builder-create__detail">
				<div className="pattern-builder-create__body">
					<VStack spacing={ 6 }>
						<VStack spacing={ 2 }>
							<h3 className="pattern-builder-create__title">
								{ kind.label }
							</h3>
							<p className="pattern-builder-create__description">
								{ kind.description }
							</p>
						</VStack>

						<VStack spacing={ 4 }>
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Name', 'pattern-builder' ) }
								value={ values.title }
								onChange={ ( value ) =>
									setValue( { title: value } )
								}
							/>
							<TextareaControl
								__nextHasNoMarginBottom
								label={ __( 'Description', 'pattern-builder' ) }
								value={ values.description }
								rows={ 3 }
								placeholder={ __(
									'A short description of the pattern',
									'pattern-builder'
								) }
								onChange={ ( value ) =>
									setValue( { description: value } )
								}
							/>
							{ kindHasField( kind, STORAGE_FIELD ) && (
								<StorageField
									value={ values.source }
									onChange={ ( value ) =>
										setValue( { source: value } )
									}
								/>
							) }
							{ kindHasField( kind, POST_TYPES_FIELD ) && (
								<PostTypesField
									value={ values.postTypes }
									onChange={ ( value ) =>
										setValue( { postTypes: value } )
									}
								/>
							) }
						</VStack>
					</VStack>
				</div>

				<div className="pattern-builder-create__footer">
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ isCreating || ! canCreate( kind, values ) }
						accessibleWhenDisabled
						isBusy={ isCreating }
						onClick={ create }
					>
						{ __( 'Create This Pattern', 'pattern-builder' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};
