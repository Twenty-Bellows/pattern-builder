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
	useBindablePostTypes,
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
	const bindable = useBindablePostTypes();
	const sources = useBindingSources( lens );

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

	const hasFields = sources.some( ( source ) => source.fields.length > 0 );
	const nothingBindable = bindable?.length === 0;

	return (
		<VStack spacing={ 4 } className="pattern-builder-bindings">
			<PostTypeLens
				value={ lens }
				onChange={ setLens }
				patternPostTypes={ patternPostTypes }
				bindable={ bindable }
			/>

			{ nothingBindable && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No post type on this site has fields to bind to. A post type qualifies by registering meta with show_in_rest, or through the pattern_builder_binding_fields filter.',
						'pattern-builder'
					) }
				</Notice>
			) }

			{ !! lens && ! hasFields && (
				<Notice status="info" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: a post type slug, such as "post". */
						__(
							'No registered source publishes bindable fields for %s. A source declares them in JavaScript, or through the pattern_builder_binding_fields filter when it is registered in PHP.',
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
					sources={ sources }
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
