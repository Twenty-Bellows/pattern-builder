import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { store as editorStore } from '@wordpress/editor';

/**
 * The reason, said once, wherever a pattern whose file runs PHP is shown.
 *
 * Core reads a pattern file by running it and keeping the output, and so does this plugin.
 * What the editor is showing is therefore a snapshot of one run — one side of every
 * branch, every rendered block frozen as HTML, every translated string in one language —
 * rather than the file. The server refuses to write that back over the program; this says
 * so before anyone spends work on it.
 */
const MESSAGE = __(
	'This pattern’s file contains PHP, so what you see here is the result of running it once — not the file itself. Pattern Builder will not save over it. Edit the file in the theme instead.',
	'pattern-builder'
);

/**
 * The notice itself, for anywhere a pattern is displayed.
 *
 * @param {Object}  root0              Component props.
 * @param {boolean} root0.hasCustomPhp Whether the file holds PHP the plugin did not write.
 */
export const PatternPhpNotice = ( { hasCustomPhp } ) => {
	if ( ! hasCustomPhp ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			{ MESSAGE }
		</Notice>
	);
};

/**
 * Takes the Save button away for as long as such a pattern is open.
 *
 * The server refuses the write either way, but a save that fails at the server costs
 * whatever was typed first. This is the same lock core uses for a post that is not ready
 * to be saved, and it is released when the pattern is closed.
 *
 * @param {boolean} hasCustomPhp Whether the file holds PHP the plugin did not write.
 */
export const usePatternCustomPhpSaveLock = ( hasCustomPhp ) => {
	const { lockPostSaving, unlockPostSaving } = useDispatch( editorStore );

	useEffect( () => {
		if ( ! hasCustomPhp ) {
			return undefined;
		}

		lockPostSaving( 'pattern-builder/has-php' );

		return () => unlockPostSaving( 'pattern-builder/has-php' );
	}, [ hasCustomPhp, lockPostSaving, unlockPostSaving ] );
};
