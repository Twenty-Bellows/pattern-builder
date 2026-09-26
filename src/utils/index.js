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

export { PatternSaveMonitor } from './patternSaveMonitor';
