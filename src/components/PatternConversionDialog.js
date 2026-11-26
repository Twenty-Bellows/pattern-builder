import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, Flex, FlexItem, Modal } from '@wordpress/components';

/**
 * One line for a theme file or a plugin's pattern.
 *
 * @param {Object} place A file (`{ kind, file }`) or a plugin pattern (`{ kind, name }`).
 * @return {string} What to show.
 */
function describeThemePlace( place ) {
	return place.file ?? place.name;
}

/**
 * One line for a row whose reference moves.
 *
 * @param {Object} post `{ id, title, postType, status }`.
 * @return {string} What to show.
 */
function describePost( post ) {
	return sprintf(
		/* translators: 1: a post's title, 2: its post type, e.g. page. */
		__( '%1$s (%2$s)', 'pattern-builder' ),
		post.title || __( '(no title)', 'pattern-builder' ),
		post.postType
	);
}

/**
 * Asks before a conversion that reaches beyond the pattern itself, or says why one cannot
 * happen.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.preview   What `pattern-builder/v1/conversion` answered.
 * @param {boolean}  props.toTheme   Whether the pattern is becoming a theme pattern.
 * @param {boolean}  props.isBusy    Whether the conversion is running.
 * @param {Function} props.onConfirm Called to go ahead.
 * @param {Function} props.onCancel  Called to close without converting.
 */
export function PatternConversionDialog( {
	preview,
	toTheme,
	isBusy,
	onConfirm,
	onCancel,
} ) {
	const blocked = preview.blockers.length > 0;
	const unnamed = preview.postTotal - preview.posts.length;

	if ( blocked ) {
		return (
			<Modal
				title={ __(
					'This pattern is used by the theme',
					'pattern-builder'
				) }
				onRequestClose={ onCancel }
				className="pattern-builder-conversion"
				size="medium"
			>
				<p>
					{ __(
						'A user pattern lives in this site’s database, and a theme file can’t take a database ID with it to another site. Remove the pattern from these places first, then convert it.',
						'pattern-builder'
					) }
				</p>
				<ul>
					{ preview.blockers.map( ( place ) => (
						<li key={ describeThemePlace( place ) }>
							<code>{ describeThemePlace( place ) }</code>
						</li>
					) ) }
				</ul>
				<Flex justify="flex-end">
					<FlexItem>
						<Button variant="primary" onClick={ onCancel }>
							{ __( 'Close', 'pattern-builder' ) }
						</Button>
					</FlexItem>
				</Flex>
			</Modal>
		);
	}

	return (
		<Modal
			title={
				toTheme
					? __( 'Convert to a Theme Pattern?', 'pattern-builder' )
					: __( 'Convert to a User Pattern?', 'pattern-builder' )
			}
			onRequestClose={ isBusy ? () => {} : onCancel }
			className="pattern-builder-conversion"
			size="medium"
		>
			<p>
				{ __(
					'This pattern is in use. Each of these places will be updated to point at the converted pattern, and will keep any content it fills in.',
					'pattern-builder'
				) }
			</p>
			<ul>
				{ preview.files.map( ( place ) => (
					<li key={ place.file }>
						<code>{ place.file }</code>
					</li>
				) ) }
				{ preview.posts.map( ( post ) => (
					<li key={ post.id }>{ describePost( post ) }</li>
				) ) }
				{ unnamed > 0 && (
					<li>
						{ sprintf(
							/* translators: %d: how many more posts. */
							_n(
								'and %d more',
								'and %d more',
								unnamed,
								'pattern-builder'
							),
							unnamed
						) }
					</li>
				) }
			</ul>
			<Flex justify="flex-end">
				<FlexItem>
					<Button
						variant="tertiary"
						disabled={ isBusy }
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'pattern-builder' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						isBusy={ isBusy }
						disabled={ isBusy }
						onClick={ onConfirm }
					>
						{ __( 'Convert', 'pattern-builder' ) }
					</Button>
				</FlexItem>
			</Flex>
		</Modal>
	);
}
