/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useInstanceId } from '@wordpress/compose';
import {
	CheckboxControl,
	TextControl,
	TextareaControl,
	Button,
	Icon,
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { chevronRight } from '@wordpress/icons';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import { navigateToPattern } from '../utils/patternNavigation';
import { BlockTypePicker } from './BlockTypePicker';
import {
	PATTERN_KIND_GROUPS,
	DESIGN,
	STORAGE_FIELD,
	POST_TYPES_FIELD,
	BLOCK_TYPES_FIELD,
	TEMPLATE_TYPES_FIELD,
	TEMPLATE_PART_AREA_FIELD,
	TEMPLATE_TYPES,
	TEMPLATE_PART_AREAS,
	getPatternKind,
	getPatternKindsInGroup,
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
		<div className="pattern-builder-create__field">
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
		</div>
	);
}

/**
 * A field that collects several values out of a known, short vocabulary.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    The question the checkboxes answer.
 * @param {string}   props.legend   The group's name, for assistive technology.
 * @param {Object[]} props.options  `{ slug, label }` for each checkbox.
 * @param {string[]} props.value    The chosen slugs.
 * @param {Function} props.onChange Called with the chosen slugs.
 */
function CheckboxGridField( { label, legend, options, value, onChange } ) {
	const toggle = ( slug, checked ) =>
		onChange(
			checked
				? [ ...value, slug ]
				: value.filter( ( item ) => item !== slug )
		);

	return (
		<div className="pattern-builder-create__field">
			<p className="pattern-builder-create__field-label">{ label }</p>
			<div
				className="pattern-builder-create__checkboxes"
				role="group"
				aria-label={ legend }
			>
				{ options.map( ( option ) => (
					<CheckboxControl
						key={ option.slug }
						__nextHasNoMarginBottom
						label={ option.label }
						checked={ value.includes( option.slug ) }
						onChange={ ( checked ) =>
							toggle( option.slug, checked )
						}
					/>
				) ) }
			</div>
		</div>
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

	return (
		<CheckboxGridField
			label={ __(
				'Which post types should offer this pattern?',
				'pattern-builder'
			) }
			legend={ __( 'Post types', 'pattern-builder' ) }
			options={ postTypes }
			value={ value }
			onChange={ onChange }
		/>
	);
}

/**
 * Which templates the pattern is offered for.
 *
 * @param {Object}   props          Component props.
 * @param {string[]} props.value    The chosen template type slugs.
 * @param {Function} props.onChange Called with the chosen slugs.
 */
function TemplateTypesField( { value, onChange } ) {
	return (
		<CheckboxGridField
			label={ __(
				'Which templates should offer this pattern?',
				'pattern-builder'
			) }
			legend={ __( 'Template types', 'pattern-builder' ) }
			options={ TEMPLATE_TYPES }
			value={ value }
			onChange={ onChange }
		/>
	);
}

/**
 * Which template part the pattern belongs to.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.value    The chosen area key.
 * @param {Function} props.onChange Called with the chosen area key.
 */
function TemplatePartAreaField( { value, onChange } ) {
	return (
		<ToggleGroupControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Which part is this pattern for?', 'pattern-builder' ) }
			value={ value }
			onChange={ onChange }
		>
			{ TEMPLATE_PART_AREAS.map( ( area ) => (
				<ToggleGroupControlOption
					key={ area.key }
					value={ area.key }
					label={ area.label }
				/>
			) ) }
		</ToggleGroupControl>
	);
}

/**
 * The kinds, grouped, as a list to pick from.
 *
 * In the modal the pick swaps the pane beside it, so the chosen kind stays
 * marked; in the editor sidebar the pick is a navigation to the kind's own
 * screen, which is why the rows carry a chevron there and nothing is marked.
 *
 * @param {Object}   props              Component props.
 * @param {string}   props.selectedKind The chosen kind's key, where one stays chosen.
 * @param {Function} props.onSelect     Called with a kind key.
 * @param {string}   props.layout       'columns' (the modal) or 'stacked' (the editor sidebar).
 */
export function PatternKindList( {
	selectedKind,
	onSelect,
	layout = 'columns',
} ) {
	const isStacked = 'stacked' === layout;
	const groupId = useInstanceId(
		PatternKindList,
		'pattern-builder-create-group'
	);

	return (
		<div
			className={
				'pattern-builder-create__kinds' +
				( isStacked ? ' is-stacked' : '' )
			}
			role="group"
			aria-label={ __( 'Kind of pattern', 'pattern-builder' ) }
		>
			{ PATTERN_KIND_GROUPS.map( ( group ) => (
				<div
					key={ group.key }
					className="pattern-builder-create__group"
					role="group"
					aria-labelledby={ `${ groupId }-${ group.key }` }
				>
					<h4
						id={ `${ groupId }-${ group.key }` }
						className="pattern-builder-create__group-label"
					>
						{ group.label }
					</h4>
					{ getPatternKindsInGroup( group.key ).map( ( item ) => (
						<button
							key={ item.key }
							type="button"
							aria-pressed={
								isStacked
									? undefined
									: item.key === selectedKind
							}
							className={
								'pattern-builder-create__kind' +
								( ! isStacked && item.key === selectedKind
									? ' is-selected'
									: '' )
							}
							onClick={ () => onSelect( item.key ) }
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
							{ isStacked && <Icon icon={ chevronRight } /> }
						</button>
					) ) }
				</div>
			) ) }
		</div>
	);
}

/**
 * One kind of pattern: what it is for, what it still needs, and the button
 * that creates it.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.kind      The chosen kind.
 * @param {Function} props.onCreated Called with the created pattern.
 * @param {string}   props.layout    'columns' (the modal) or 'stacked' (the editor sidebar).
 */
export function PatternCreateForm( { kind, onCreated, layout = 'columns' } ) {
	const { onNavigateToEntityRecord } = useSelect( ( select ) => {
		const { getSettings } = select( blockEditorStore );
		return {
			onNavigateToEntityRecord: getSettings().onNavigateToEntityRecord,
		};
	}, [] );

	const [ values, setValues ] = useState( () => getInitialValues( kind ) );
	const [ error, setError ] = useState( '' );
	const [ isCreating, setIsCreating ] = useState( false );

	// Where the kind can be swapped without leaving the form, its own fields
	// start again; what the user has typed is theirs and stays.
	useEffect( () => {
		setValues( ( current ) => ( {
			...getInitialValues( kind ),
			title: current.title,
			description: current.description,
		} ) );
		setError( '' );
	}, [ kind ] );

	const setValue = ( edit ) =>
		setValues( ( current ) => ( { ...current, ...edit } ) );

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

	const isStacked = 'stacked' === layout;

	return (
		<div
			className={
				'pattern-builder-create__detail' +
				( isStacked ? ' is-stacked' : '' )
			}
		>
			<div className="pattern-builder-create__body">
				<div className="pattern-builder-create__intro">
					{ /* Stacked, the screen this form is on is already named
					     after the kind. */ }
					{ ! isStacked && (
						<h3 className="pattern-builder-create__title">
							{ kind.label }
						</h3>
					) }
					<p className="pattern-builder-create__description">
						{ kind.description }
					</p>
				</div>

				<div className="pattern-builder-create__fields">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'pattern-builder' ) }
						value={ values.title }
						onChange={ ( value ) => setValue( { title: value } ) }
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
					{ kindHasField( kind, TEMPLATE_TYPES_FIELD ) && (
						<TemplateTypesField
							value={ values.templateTypes }
							onChange={ ( value ) =>
								setValue( { templateTypes: value } )
							}
						/>
					) }
					{ kindHasField( kind, TEMPLATE_PART_AREA_FIELD ) && (
						<TemplatePartAreaField
							value={ values.templatePartArea }
							onChange={ ( value ) =>
								setValue( { templatePartArea: value } )
							}
						/>
					) }
					{ kindHasField( kind, BLOCK_TYPES_FIELD ) && (
						<BlockTypePicker
							label={ __(
								'Which blocks should offer this pattern?',
								'pattern-builder'
							) }
							value={ values.blockTypes }
							onChange={ ( value ) =>
								setValue( { blockTypes: value } )
							}
						/>
					) }
				</div>
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
	);
}

/**
 * Creates a pattern from one of a few kinds, both panes at once.
 *
 * The kinds sit on the left; picking one describes what that kind of pattern
 * is for and asks only for what it leaves open. The editor sidebar has no
 * room for two panes, so it puts these same two pieces on two screens.
 *
 * @param {Object}   props           Component props.
 * @param {Function} props.onCreated Called with the created pattern.
 */
export const PatternCreatePanel = ( { onCreated } ) => {
	const [ kindKey, setKindKey ] = useState( DESIGN );

	return (
		<div className="pattern-builder-create">
			<PatternKindList selectedKind={ kindKey } onSelect={ setKindKey } />
			<PatternCreateForm
				kind={ getPatternKind( kindKey ) }
				onCreated={ onCreated }
			/>
		</div>
	);
};
