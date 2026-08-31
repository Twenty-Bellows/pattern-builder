import { __ } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalText as Text,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';

/**
 * Toggles a pattern between synced and unsynced.
 *
 * For a theme pattern (pb_pattern) the choice is the `synced` entity field,
 * persisted as the pattern file's `Synced: yes` header on the next save. For
 * a user pattern (wp_block) it is core's `wp_pattern_sync_status` meta.
 *
 * @param {Object} root0             Component props.
 * @param {Object} root0.patternPost The pattern's entity record.
 * @param {string} root0.postType    The pattern's post type.
 */
export const PatternSyncedStatusPanel = ( { patternPost, postType } ) => {
	const isThemePattern = postType === 'pb_pattern';

	const getSyncedValue = () => {
		if ( isThemePattern ) {
			return patternPost.synced ? 'true' : 'false';
		}

		return patternPost.wp_pattern_sync_status === 'unsynced' ||
			patternPost.meta?.wp_pattern_sync_status === 'unsynced'
			? 'false'
			: 'true';
	};

	const [ synced, setSynced ] = useState( getSyncedValue() );

	useEffect( () => {
		setSynced( getSyncedValue() );
	}, [
		patternPost.synced,
		patternPost.wp_pattern_sync_status,
		patternPost.meta?.wp_pattern_sync_status,
	] );

	if ( ! patternPost ) {
		return null;
	}

	const changeSyncedStatus = ( value ) => {
		setSynced( value );

		if ( isThemePattern ) {
			dispatch( 'core' ).editEntityRecord(
				'postType',
				'pb_pattern',
				patternPost.id,
				{ synced: value === 'true' }
			);
			return;
		}

		/*
		 * Core registers the meta with enum [partial, unsynced] — an empty
		 * string fails REST validation. Synced is the ABSENCE of the meta,
		 * and null is the REST meta API's delete.
		 */
		dispatch( 'core' ).editEntityRecord(
			'postType',
			'wp_block',
			patternPost.id,
			{
				meta: {
					...( patternPost.meta || {} ),
					wp_pattern_sync_status:
						value === 'true' ? null : 'unsynced',
				},
			}
		);
	};

	return (
		<>
			<div className="components-base-control">
				<p className="components-base-control__label">
					{ __(
						'Should this pattern be synced?',
						'pattern-builder'
					) }
				</p>
				<ToggleGroupControl
					value={ synced === 'true' ? 'true' : 'false' }
					onChange={ ( value ) => {
						changeSyncedStatus( value );
					} }
					__nextHasNoMarginBottom
				>
					<ToggleGroupControlOption
						value="true"
						label={ __( 'Synced', 'pattern-builder' ) }
						tooltip={ __(
							'Synced Patterns can be reused across your site and will be updated automatically when the original pattern is updated. Certain parts of the pattern (text and images) can be customized wherever they are used. This is useful for patterns that are used in multiple places and when you wish your design to be preserved and easily updated.',
							'pattern-builder'
						) }
					/>
					<ToggleGroupControlOption
						value="false"
						label={ __( 'Unsynced', 'pattern-builder' ) }
						tooltip={ __(
							'Unsynced Patterns can be customized freely and will not update automatically when the original pattern is updated. This is useful for one-off designs or when you want to have full control over the pattern without worrying about updates.',
							'pattern-builder'
						) }
					/>
				</ToggleGroupControl>
			</div>
		</>
	);
};
