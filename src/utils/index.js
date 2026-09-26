/**
 * Utility exports
 */

export {
	getLocalStorageValue,
	setLocalStorageValue,
	removeLocalStorageValue,
	getLocalizePatternsSetting,
	setLocalizePatternsSetting,
	getImportImagesSetting,
	setImportImagesSetting,
} from './localStorage';

export { fetchAllPatterns, fetchPluginPatterns } from './resolvers';

export { PLUGIN_ORIGIN_SCHEME } from './origin';

export { PatternSaveMonitor } from './patternSaveMonitor';
