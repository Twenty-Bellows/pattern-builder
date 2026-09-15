import { __, sprintf } from '@wordpress/i18n';
import { Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';

import {
	OVERRIDES_SOURCE,
	isOverride,
	isSameBinding,
} from '../../utils/bindings';

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
 * A binding the panel cannot match to a field — written by hand, or pointing at
 * a source the current post type does not reach — is named rather than hidden,
 * so it can be seen and changed.
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

	const value = Object.values( binding.args || {} )[ 0 ];
	const label = source?.label || binding.source;

	return {
		text: value ? `${ label } · ${ value }` : label,
		tone: 'muted',
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

								if ( compatible.length === 0 ) {
									return null;
								}

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
									</MenuGroup>
								);
							} ) }
						</>
					);
				} }
			/>
		</div>
	);
};

export default AttributeBindingRow;
