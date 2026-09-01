/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useMemo, useRef } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { FormTokenField } from '@wordpress/components';
import { store as blocksStore } from '@wordpress/blocks';

/**
 * A block name a pattern file can carry: `namespace/slug`.
 */
const BLOCK_NAME = /^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/;

/**
 * The blocks worth offering a pattern for.
 *
 * Blocks that only exist inside another block (a column, a list item) and
 * blocks the inserter itself hides are left out: a pattern is offered where
 * a block is inserted, which those blocks never are on their own.
 *
 * @param {Object[]} blockTypes Registered block types.
 * @return {Object[]} The block types a pattern can sensibly belong to.
 */
export function getOfferableBlockTypes( blockTypes ) {
	return blockTypes.filter(
		( blockType ) =>
			false !== blockType.supports?.inserter &&
			! blockType.parent?.length &&
			! blockType.ancestor?.length
	);
}

/**
 * Labels for the block types, unique enough to be tokens.
 *
 * The field talks in block titles because that is what the block is called
 * everywhere else; the pattern file records `core/cover`. Two blocks are
 * allowed to share a title, so a shared one carries its name as well.
 *
 * @param {Object[]} blockTypes Block types to label.
 * @return {Object[]} `{ name, label }`, sorted by label.
 */
export function getBlockChoices( blockTypes ) {
	const seen = {};

	blockTypes.forEach( ( blockType ) => {
		seen[ blockType.title ] = ( seen[ blockType.title ] || 0 ) + 1;
	} );

	return blockTypes
		.map( ( blockType ) => ( {
			name: blockType.name,
			label:
				seen[ blockType.title ] > 1
					? `${ blockType.title } (${ blockType.name })`
					: blockType.title,
		} ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );
}

/**
 * The block name a token stands for.
 *
 * A token is usually a label the field suggested. Typing a block name
 * straight in is allowed too — a pattern may name a block that belongs to a
 * plugin this site does not have — but anything else is not a block and is
 * dropped rather than written into the pattern file.
 *
 * @param {string}   token   What the user entered or picked.
 * @param {Object[]} choices The labelled block types.
 * @return {string|null} A block name, or null when the token is neither.
 */
export function tokenToBlockName( token, choices ) {
	const trimmed = String( token ).trim();
	const choice = choices.find(
		( item ) => item.label.toLowerCase() === trimmed.toLowerCase()
	);

	if ( choice ) {
		return choice.name;
	}

	return BLOCK_NAME.test( trimmed ) ? trimmed : null;
}

/**
 * Picks block types from everything registered on this site.
 *
 * There are far too many blocks for a list of checkboxes, so this is core's
 * token field: type to narrow the list, click the field to browse all of
 * them, and each pick becomes a token.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    The field's label.
 * @param {string[]} props.value    The chosen block names.
 * @param {Function} props.onChange Called with the chosen block names.
 */
export function BlockTypePicker( { label, value, onChange } ) {
	const fieldRef = useRef();
	const blockTypes = useSelect(
		( select ) =>
			getOfferableBlockTypes( select( blocksStore ).getBlockTypes() ),
		[]
	);

	const choices = useMemo(
		() => getBlockChoices( blockTypes ),
		[ blockTypes ]
	);

	// Tokens read as block titles; a block this site does not have keeps its
	// name, which is all there is to show.
	const tokens = value.map(
		( name ) =>
			choices.find( ( choice ) => choice.name === name )?.label || name
	);

	/*
	 * The suggestions open below the field rather than over the top of it,
	 * and the pane they open in scrolls — so a field sitting near the bottom
	 * would drop its list out of sight. Wait for the list to render, then
	 * bring the whole field into view.
	 */
	const revealSuggestions = () => {
		window.requestAnimationFrame( () =>
			window.requestAnimationFrame( () =>
				fieldRef.current?.scrollIntoView( {
					block: 'end',
					behavior: 'smooth',
				} )
			)
		);
	};

	return (
		<div ref={ fieldRef } onFocus={ revealSuggestions }>
			<FormTokenField
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				__experimentalExpandOnFocus
				__experimentalShowHowTo={ false }
				label={ label || __( 'Blocks', 'pattern-builder' ) }
				placeholder={ __( 'Search blocks', 'pattern-builder' ) }
				value={ tokens }
				suggestions={ choices.map( ( choice ) => choice.label ) }
				tokenizeOnBlur
				onChange={ ( nextTokens ) =>
					onChange(
						nextTokens
							.map( ( token ) =>
								tokenToBlockName( token, choices )
							)
							.filter( Boolean )
					)
				}
			/>
		</div>
	);
}
