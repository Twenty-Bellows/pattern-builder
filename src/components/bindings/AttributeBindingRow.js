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
import { OPAQUE_ARG } from './use-binding-sources';

/**
 * What the row says the attribute is currently connected to.
 *
 * @param {?Object} binding The attribute's binding.
 * @param {Array}   listed  Sources that published a field list.
 * @param {Array}   opaque  Sources that cannot describe themselves.
 * @return {{text: string, tone: string}} The label and how it should read:
 *         `muted` for an unconnected attribute, `invalid` for a binding whose
 *         source has gone away, `connected` otherwise.
 */
function describeBinding( binding, listed, opaque ) {
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

	const source =
		listed.find( ( candidate ) => candidate.name === binding.source ) ||
		opaque.find( ( candidate ) => candidate.name === binding.source );

	if ( ! source ) {
		return {
			text: __( 'Source not registered', 'pattern-builder' ),
			tone: 'invalid',
		};
	}

	const field = ( source.fields || [] ).find( ( candidate ) =>
		isSameBinding( { source: source.name, args: candidate.args }, binding )
	);

	if ( field ) {
		return { text: field.label, tone: 'connected' };
	}

	/*
	 * A binding whose source is registered but whose field is not in the list:
	 * a typed key, or a field that belongs to a different post type than the
	 * one the panel is currently listing. Naming both is more use than naming
	 * either.
	 */
	const key = binding.args?.[ OPAQUE_ARG ];

	return {
		text: key ? `${ source.label } · ${ key }` : source.label,
		tone: 'connected',
	};
}

/**
 * One bindable attribute of one block.
 *
 * The menu offers the two pattern-level choices and then every registered
 * source: those that published fields for the current post type as a list to
 * pick from, and those that cannot describe themselves as a typed key. Core's
 * own panel offers only the first kind and hides the rest, which is what makes
 * it seem to appear and disappear at random.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.attribute   The attribute name.
 * @param {string}   props.type        The type its bindings must match.
 * @param {?Object}  props.binding     The attribute's current binding.
 * @param {Array}    props.listed      Sources that published a field list.
 * @param {Array}    props.opaque      Sources that cannot describe themselves.
 * @param {boolean}  props.canOverride Whether the block is named, and so can
 *                                     carry pattern overrides.
 * @param {Function} props.onChange    Called with the new binding, or
 *                                     `undefined` to disconnect.
 */
export const AttributeBindingRow = ( {
	attribute,
	type,
	binding,
	listed,
	opaque,
	canOverride,
	onChange,
} ) => {
	const { text, tone } = describeBinding( binding, listed, opaque );
	const isTypedKey =
		!! binding &&
		! isOverride( binding ) &&
		opaque.some( ( source ) => source.name === binding.source );

	const valueClass = `pattern-builder-bindings__value is-${ tone }`;

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
							/* translators: 1: block attribute name, such as "content". 2: what it is connected to, such as "Overridable". */
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
						<span className={ valueClass }>{ text }</span>
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

							{ listed.map( ( source ) => {
								const compatible = source.fields.filter(
									( field ) => field.type === type
								);

								return (
									<MenuGroup
										key={ source.name }
										label={ source.label }
									>
										{ compatible.length === 0 && (
											<p className="pattern-builder-bindings__empty">
												{ __(
													'No fields of this type.',
													'pattern-builder'
												) }
											</p>
										) }
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
									</MenuGroup>
								);
							} ) }

							{ opaque.length > 0 && (
								<MenuGroup
									label={ __(
										'Enter a key',
										'pattern-builder'
									) }
								>
									{ opaque.map( ( source ) => (
										<MenuItem
											key={ source.name }
											role="menuitemradio"
											isSelected={
												binding?.source === source.name
											}
											info={ source.name }
											onClick={ () =>
												pick( {
													source: source.name,
													args: {
														[ OPAQUE_ARG ]:
															binding?.args?.[
																OPAQUE_ARG
															] || '',
													},
												} )
											}
										>
											{ source.label }
										</MenuItem>
									) ) }
								</MenuGroup>
							) }
						</>
					);
				} }
			/>

			{ isTypedKey && (
				<div className="pattern-builder-bindings__key">
					<TextControl
						label={ __( 'Key', 'pattern-builder' ) }
						value={ binding.args?.[ OPAQUE_ARG ] || '' }
						onChange={ ( value ) =>
							onChange( {
								source: binding.source,
								args: { [ OPAQUE_ARG ]: value },
							} )
						}
						placeholder={ __( 'field_name', 'pattern-builder' ) }
						help={ sprintf(
							/* translators: %s: binding source name, such as "acf/field". */
							__(
								'%s publishes no field list, so the key is typed. It reaches the source as its arguments when the block renders.',
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
