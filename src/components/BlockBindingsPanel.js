import { __, sprintf } from '@wordpress/i18n';
import {
	Notice,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';

import { BindableBlockCard } from './bindings/BindableBlockCard';
import { PostTypeLens } from './bindings/PostTypeLens';
import { useBindableBlocks } from './bindings/use-bindable-blocks';
import {
	USER_INPUT_ONLY,
	useBindingSources,
} from './bindings/use-binding-sources';
import './bindings/bindings.scss';

/**
 * Where every bindable block in the pattern gets its value.
 *
 * @param {Object}  props             Component props.
 * @param {?Object} props.patternPost The pattern's entity record, read only for
 *                                    the post types it declares.
 */
export const BlockBindingsPanel = ( { patternPost } ) => {
	const bindableBlocks = useBindableBlocks();
	const patternPostTypes = patternPost?.postTypes || [];
	const [ lens, setLens ] = useState( USER_INPUT_ONLY );
	const { listed, opaque } = useBindingSources( lens );

	if ( bindableBlocks.length === 0 ) {
		return (
			<Text variant="muted">
				{ __(
					'None of the blocks in this pattern can take their value from anywhere else.',
					'pattern-builder'
				) }
			</Text>
		);
	}

	const hasFields = listed.some( ( source ) => source.fields.length > 0 );

	return (
		<VStack spacing={ 4 } className="pattern-builder-bindings">
			<PostTypeLens
				value={ lens }
				onChange={ setLens }
				patternPostTypes={ patternPostTypes }
			/>

			{ !! lens && ! hasFields && opaque.length === 0 && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: a post type slug, such as "post". */
						__(
							'No registered binding source offers fields for %s. A source has to publish its fields to the editor to appear here, and most only expose them to the post editor.',
							'pattern-builder'
						),
						lens
					) }
				</Notice>
			) }

			{ !! lens && ! hasFields && opaque.length > 0 && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: a post type slug, such as "post". */
						__(
							'No source publishes a field list for %s, so its fields are reached by typing a key.',
							'pattern-builder'
						),
						lens
					) }
				</Notice>
			) }

			{ bindableBlocks.map( ( { block, supported } ) => (
				<BindableBlockCard
					key={ block.clientId }
					block={ block }
					supported={ supported }
					showRows={ !! lens }
					listed={ listed }
					opaque={ opaque }
				/>
			) ) }

			<Text variant="muted">
				{ __(
					'Overrides let someone change a value on one placement of a synced pattern. A binding to any other source reads its value when the page renders.',
					'pattern-builder'
				) }
			</Text>
		</VStack>
	);
};

export default BlockBindingsPanel;
