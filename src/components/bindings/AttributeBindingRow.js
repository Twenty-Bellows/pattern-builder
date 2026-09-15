import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
	TextControl,
} from '@wordpress/components';

import {
	OVERRIDES_SOURCE,
	isOverride,
	isSameBinding,
} from '../../utils/bindings';
import { DEFAULT_ARG, POST_META_SOURCE } from './use-binding-sources';

/**
 * The single argument a typed binding carries.
 *
 * @param {?Object} binding The attribute's binding.
 * @return {{name: string, value: string}} The argument name and its value.
 */
function typedArg( binding ) {
	const name = Object.keys( binding?.args || {} )[ 0 ] || DEFAULT_ARG;

	return { name, value: binding?.args?.[ name ] ?? '' };
}

/**
 * The published field a binding points at, if any.
 *
 * @param {?Object} binding The attribute's binding.
 * @param {?Object} source  The source it names.
 * @return {?Object} The matching field.
 */
function matchField( binding, source ) {
	return ( source?.fields || [] ).find( ( field ) =>
		isSameBinding( { source: source.name, args: field.args }, binding )
	);
}

/**
 * What the row says the attribute is connected to.
 *
 * @param {?Object} binding The attribute's binding.
 * @param {Array}   sources The registered sources.
 * @return {{text: string, tone: string}} The label, and `muted` or `connected`.
 */
function describeBinding( binding, sources ) {
	if ( ! binding ) {
		return {
			text: __( 'Not connected', 'pattern-builder' ),
			tone: 'muted',
		};
	}

	if ( isOverride( binding ) ) {
		return {
			text: __( 'Overridable', 'pattern-builder' ),
			tone: 'connected',
		};
	}

	const source = sources.find(
		( candidate ) => candidate.name === binding.source
	);
	const field = matchField( binding, source );

	if ( field ) {
		return { text: field.label, tone: 'connected' };
	}

	const { value } = typedArg( binding );
	const label = source?.label || binding.source;

	return {
		text: value ? `${ label } · ${ value }` : label,
		tone: source ? 'connected' : 'muted',
	};
}

/**
 * One bindable attribute of one block.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.attribute   The attribute name.
 * @param {string}   props.type        The type its bindings must match.
 * @param {?Object}  props.binding     The attribute's current binding.
 * @param {Array}    props.sources     The registered sources.
 * @param {boolean}  props.canOverride Whether the block is named.
 * @param {Function} props.onChange    Called with the new binding, or `undefined`.
 */
export const AttributeBindingRow = ( {
	attribute,
	type,
	binding,
	sources,
	canOverride,
	onChange,
} ) => {
	const { text, tone } = describeBinding( binding, sources );
	const boundSource = sources.find(
		( candidate ) => candidate.name === binding?.source
	);
	const isTyped =
		!! binding &&
		! isOverride( binding ) &&
		! matchField( binding, boundSource );
	const arg = typedArg( binding );

	const setArg = ( name, value ) =>
		onChange( { source: binding.source, args: { [ name ]: value } } );

	return (
		<div className="pattern-builder-bindings__row">
			<Dropdown
				className="pattern-builder-bindings__dropdown"
				popoverProps={ { placement: 'left-start', offset: 36 } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						className="pattern-builder-bindings__toggle"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						aria-haspopup="true"
						label={ sprintf(
							/* translators: 1: attribute name. 2: what it is connected to. */
							__( '%1$s: %2$s', 'pattern-builder' ),
							attribute,
							text
						) }
						showTooltip={ false }
					>
						<span className="pattern-builder-bindings__attribute">
							{ attribute }
							<span className="pattern-builder-bindings__type">
								{ type }
							</span>
						</span>
						<span
							className={ `pattern-builder-bindings__value is-${ tone }` }
						>
							{ text }
						</span>
					</Button>
				) }
				renderContent={ ( { onClose } ) => {
					const pick = ( value ) => {
						onChange( value );
						onClose();
					};

					return (
						<>
							<MenuGroup
								label={ __(
									'This pattern',
									'pattern-builder'
								) }
							>
								<MenuItem
									role="menuitemradio"
									isSelected={ ! binding }
									onClick={ () => pick( undefined ) }
								>
									{ __( 'Not connected', 'pattern-builder' ) }
								</MenuItem>
								<MenuItem
									role="menuitemradio"
									isSelected={ isOverride( binding ) }
									disabled={ ! canOverride }
									info={
										canOverride
											? OVERRIDES_SOURCE
											: __(
													'Name the block first',
													'pattern-builder'
											  )
									}
									onClick={ () =>
										pick( { source: OVERRIDES_SOURCE } )
									}
								>
									{ __( 'Overridable', 'pattern-builder' ) }
								</MenuItem>
							</MenuGroup>

							{ sources.map( ( source ) => {
								const compatible = source.fields.filter(
									( field ) => field.type === type
								);
								const typedHere =
									isTyped && binding.source === source.name;

								return (
									<MenuGroup
										key={ source.name }
										label={ source.label }
									>
										{ compatible.map( ( field ) => {
											const value = {
												source: source.name,
												args: field.args,
											};

											return (
												<MenuItem
													key={ JSON.stringify(
														field.args
													) }
													role="menuitemradio"
													isSelected={ isSameBinding(
														binding,
														value
													) }
													info={ source.name }
													onClick={ () =>
														pick( value )
													}
												>
													{ field.label }
												</MenuItem>
											);
										} ) }
										<MenuItem
											role="menuitemradio"
											isSelected={ typedHere }
											info={
												source.name === POST_META_SOURCE
													? __(
															'Only meta registered with show_in_rest resolves',
															'pattern-builder'
													  )
													: source.name
											}
											onClick={ () =>
												pick( {
													source: source.name,
													args: {
														[ typedHere
															? arg.name
															: DEFAULT_ARG ]:
															typedHere
																? arg.value
																: '',
													},
												} )
											}
										>
											{ compatible.length === 0
												? __(
														'Enter a value…',
														'pattern-builder'
												  )
												: __(
														'Enter another value…',
														'pattern-builder'
												  ) }
										</MenuItem>
									</MenuGroup>
								);
							} ) }
						</>
					);
				} }
			/>

			{ isTyped && (
				<div className="pattern-builder-bindings__key">
					<TextControl
						label={ __( 'Argument', 'pattern-builder' ) }
						value={ arg.name }
						onChange={ ( name ) =>
							setArg( name || DEFAULT_ARG, arg.value )
						}
						spellCheck={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Value', 'pattern-builder' ) }
						value={ arg.value }
						onChange={ ( value ) => setArg( arg.name, value ) }
						placeholder={ __( 'field_name', 'pattern-builder' ) }
						help={ sprintf(
							/* translators: %s: binding source name, such as "acf/field". */
							__(
								'Passed to %s as its arguments when the block renders. Most sources read "key"; Post Data and Term Data read "field".',
								'pattern-builder'
							),
							binding.source
						) }
						spellCheck={ false }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
			) }
		</div>
	);
};

export default AttributeBindingRow;
