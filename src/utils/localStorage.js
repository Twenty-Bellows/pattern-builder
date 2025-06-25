/**
 * Utility functions for managing localStorage values
 */

const STORAGE_KEY_PREFIX = 'pattern-builder-';

/**
 * Gets a value from localStorage with the pattern-builder prefix
 * @param {string} key - The key to retrieve
 * @param {*} defaultValue - Default value if key doesn't exist
 * @returns {*} The stored value or default
 */
export const getLocalStorageValue = ( key, defaultValue = null ) => {
	try {
		const item = localStorage.getItem( STORAGE_KEY_PREFIX + key );
		return item ? JSON.parse( item ) : defaultValue;
	} catch ( error ) {
		console.error( 'Error reading from localStorage:', error );
		return defaultValue;
	}
};

/**
 * Sets a value in localStorage with the pattern-builder prefix
 * @param {string} key - The key to store
 * @param {*} value - The value to store
 */
export const setLocalStorageValue = ( key, value ) => {
	try {
		localStorage.setItem( STORAGE_KEY_PREFIX + key, JSON.stringify( value ) );
	} catch ( error ) {
		console.error( 'Error writing to localStorage:', error );
	}
};

/**
 * Removes a value from localStorage with the pattern-builder prefix
 * @param {string} key - The key to remove
 */
export const removeLocalStorageValue = ( key ) => {
	try {
		localStorage.removeItem( STORAGE_KEY_PREFIX + key );
	} catch ( error ) {
		console.error( 'Error removing from localStorage:', error );
	}
};

/**
 * Gets the localize patterns setting from localStorage
 * @returns {boolean} Whether pattern localization is enabled
 */
export const getLocalizePatternsSetting = () => {
	return getLocalStorageValue( 'localizePatterns', false );
};

/**
 * Sets the localize patterns setting in localStorage
 * @param {boolean} value - Whether to enable pattern localization
 */
export const setLocalizePatternsSetting = ( value ) => {
	setLocalStorageValue( 'localizePatterns', value );
};

/**
 * Gets the import images setting from localStorage
 * @returns {boolean} Whether image importing is enabled (defaults to true)
 */
export const getImportImagesSetting = () => {
	return getLocalStorageValue( 'importImages', true );
};

/**
 * Sets the import images setting in localStorage
 * @param {boolean} value - Whether to enable image importing
 */
export const setImportImagesSetting = ( value ) => {
	setLocalStorageValue( 'importImages', value );
};