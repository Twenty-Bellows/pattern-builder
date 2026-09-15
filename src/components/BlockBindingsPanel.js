import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';

import { MODE, getBindingMode } from '../utils/bindings';
import { BindableBlockCard } from './bindings/BindableBlockCard';
import { PostTypeLens } from './bindings/PostTypeLens';
import { useBindableBlocks } from './bindings/use-bindable-blocks';
import { useBindingSources } from './bindings/use-binding-sources';
import './bindings/bindings.scss';

/**
 * Where every bindable block in the pattern gets its values.
 *
 * Each block answers one question — from the pattern file, from whoever places
 * the pattern, or from the post it lands in — and only the third one opens
 * into per-attribute detail. The post type lens appears alongside it, because
 * that is the first moment a field list can exist at all.
 *
 * @param {Object}  props             Component props.
 * @param {?Object} props.patternPost The pattern's entity record, if the
 *                                    editor has one. Only its `postTypes` are
 *                                    read, to start the lens somewhere useful.
 */
export const BlockBindingsPanel = ( { patternPost } ) => {
	const bindableBlocks = useBindableBlocks();

	// Which blocks the author has opened the per-attribute view on. A block
	// whose bindings already need that view is in it whatever this says.
	const [ expanded, setExpanded ] = useState( {} );

	const patternPostTypes = patternPost?.postTypes || [];
	const [ lens, setLens ] = useState( patternPostTypes[ 0 ] || 'post' );

	const { listed, opaque } = useBindingSources( lens );

	const cards = bindableBlocks.map( ( { block, supported } ) => {
		const derived = getBindingMode(
			block.attributes?.metadata?.bindings,
			supported
		);

		return {
			block,
			supported,
			mode:
				derived === MODE.DYNAMIC || expanded[ block.clientId ]
					? MODE.DYNAMIC
					: derived,
		};
	} );

	if ( cards.length === 0 ) {
		return (
			<Text variant="muted">
				{ __(
					'None of the blocks in this pattern can take their values from anywhere else.',
					'pattern-builder'
				) }
			</Text>
		);
	}

	const showLens = cards.some( ( card ) => card.mode === MODE.DYNAMIC );

	return (
		<VStack spacing={ 4 } className="pattern-builder-bindings">
			<Text>
				{ __(
					'Decide where each block gets its value: from the pattern file, from whoever places the pattern, or from the post it lands in.',
					'pattern-builder'
				) }
			</Text>

			{ showLens && (
				<PostTypeLens
					value={ lens }
					onChange={ setLens }
					patternPostTypes={ patternPostTypes }
				/>
			) }

			{ cards.map( ( { block, supported, mode } ) => (
				<BindableBlockCard
					key={ block.clientId }
					block={ block }
					supported={ supported }
					mode={ mode }
					listed={ listed }
					opaque={ opaque }
					onExpandedChange={ ( isExpanded ) =>
						setExpanded( ( current ) => ( {
							...current,
							[ block.clientId ]: isExpanded,
						} ) )
					}
				/>
			) ) }

			<Text variant="muted">
				{ __(
					'Overrides let someone change a value on one placement of a synced pattern. A binding to any other source reads its value when the page renders, synced or not.',
					'pattern-builder'
				) }
			</Text>
		</VStack>
	);
};

export default BlockBindingsPanel;
