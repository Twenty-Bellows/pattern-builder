import { createReduxStore, register } from '@wordpress/data';
import {
	deletePattern,
	fetchEditorConfiguration,
	savePattern,
	fetchAllPatterns,
} from './resolvers';

// Helper function to format patterns for WordPress editor settings
function formatPatternsForEditor( patterns ) {
	return patterns.map( ( pattern ) => {
		return {
			...pattern,
			syncStatus: pattern.synced ? 'fully' : 'unsynced',
			content: pattern.synced
				? `<!-- wp:block {"ref":${ pattern.id }} /-->`
				: pattern.content || '',
		};
	} );
}

const SET_ACTIVE_PATTERN = 'SET_ACTIVE_PATTERN';
const DELETE_ACTIVE_PATTERN = 'DELETE_ACTIVE_PATTERN';
const SET_EDITOR_CONFIGURATION = 'SET_EDITOR_CONFIGURATION';
const SET_ALL_PATTERNS = 'SET_ALL_PATTERNS';
const SET_FILTER_OPTIONS = 'SET_FILTER_OPTIONS';

const initialState = {
	activePattern: null,
	editorConfiguration: {},
	allPatterns: [],
	filterOptions: {
		source: 'all',
		synced: 'all',
		category: 'all',
		hidden: 'visible',
		keyword: '',
	},
};

const reducer = ( state = initialState, action ) => {
	switch ( action.type ) {
		case SET_ACTIVE_PATTERN:
			return {
				...state,
				activePattern: action.value,
			};
		case DELETE_ACTIVE_PATTERN:
			return {
				...state,
				activePattern: null,
			};
		case SET_EDITOR_CONFIGURATION:
			return {
				...state,
				editorConfiguration: {
					...action.value,
					__experimentalBlockPatterns:
						state.editorConfiguration.__experimentalBlockPatterns ||
						[],
				},
			};
		case SET_ALL_PATTERNS:
			return {
				...state,
				allPatterns: action.value,
				editorConfiguration: {
					...state.editorConfiguration,
					__experimentalBlockPatterns: formatPatternsForEditor(
						action.value
					),
				},
			};
		case SET_FILTER_OPTIONS:
			return {
				...state,
				filterOptions: {
					...state.filterOptions,
					...action.value,
				},
			};
		default:
			return state;
	}
};

const actions = {
	setActivePattern: ( value ) => ( { type: SET_ACTIVE_PATTERN, value } ),
	deleteActivePattern:
		( patternToDelete ) =>
		async ( { dispatch, select } ) => {
			if ( ! patternToDelete ) {
				throw new Error( 'No pattern to delete.' );
			}

			await deletePattern( patternToDelete );
			dispatch( actions.setActivePattern( null ) );
			dispatch( actions.fetchAllPatterns() );
		},
	setEditorConfiguration: ( value ) => ( {
		type: SET_EDITOR_CONFIGURATION,
		value,
	} ),
	fetchEditorConfiguration:
		() =>
		async ( { dispatch } ) => {
			try {
				const config = await fetchEditorConfiguration();
				dispatch( actions.setEditorConfiguration( config ) );
			} catch ( error ) {
				console.error( 'Failed to load editor configuration:', error );
			}
		},
	saveActivePattern:
		( updatedPattern ) =>
		async ( { dispatch } ) => {
			if ( ! updatedPattern ) {
				console.warn( 'No pattern provided to save.' );
				return;
			}

			const savedPattern = await savePattern( updatedPattern );
			dispatch( actions.setActivePattern( savedPattern ) );

			// Fetch all patterns to refresh the editor settings
			await dispatch( actions.fetchAllPatterns() );

			// Invalidate the WordPress core cache for this pattern
			// This forces the site editor to refetch the updated pattern
			if ( savedPattern.id ) {
				wp.data
					.dispatch( 'core' )
					.invalidateResolution( 'getEntityRecord', [
						'postType',
						'pb_block',
						savedPattern.id,
					] );
				wp.data
					.dispatch( 'core' )
					.invalidateResolution( 'getEntityRecord', [
						'postType',
						'wp_block',
						savedPattern.id,
					] );
				wp.data
					.dispatch( 'core' )
					.invalidateResolution( 'getEntityRecords', [
						'postType',
						'pb_block',
					] );
				wp.data
					.dispatch( 'core' )
					.invalidateResolution( 'getEntityRecords', [
						'postType',
						'wp_block',
					] );
			}

			return savedPattern;
		},
	fetchAllPatterns:
		() =>
		async ( { dispatch } ) => {
			try {
				const patterns = await fetchAllPatterns();
				dispatch( { type: SET_ALL_PATTERNS, value: patterns } );
			} catch ( error ) {
				console.error( 'Failed to fetch all patterns:', error );
			}
		},
	setFilterOptions: ( value ) => ( { type: SET_FILTER_OPTIONS, value } ),
};

const selectors = {
	getActivePattern: ( state ) => state.activePattern,
	getEditorConfiguration: ( state ) => state.editorConfiguration,
	getAllPatterns: ( state ) => state.allPatterns,
	getFilterOptions: ( state ) => state.filterOptions,
};

const store = createReduxStore( 'pattern-builder', {
	reducer,
	actions,
	selectors,
} );

register( store );

export default store;
