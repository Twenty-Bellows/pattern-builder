import { createReduxStore, register } from '@wordpress/data';
import { deletePattern, fetchEditorConfiguration, savePattern, fetchAllPatterns } from './resolvers';

// Action types
const SET_ACTIVE_PATTERN = 'SET_ACTIVE_PATTERN';
const DELETE_ACTIVE_PATTERN = 'DELETE_ACTIVE_PATTERN';
const SET_EDITOR_CONFIGURATION = 'SET_EDITOR_CONFIGURATION';
const SET_ALL_PATTERNS = 'SET_ALL_PATTERNS';

// Initial state
const initialState = {
    activePattern: null,
    editorConfiguration: {},
    allPatterns: [],
};

// Reducer
const reducer = (state = initialState, action) => {
    switch (action.type) {
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
                editorConfiguration: action.value,
            };
        case SET_ALL_PATTERNS:
            return {
                ...state,
                allPatterns: action.value,
            };
        default:
            return state;
    }
};

// Actions
const actions = {
    setActivePattern: (value) => ({ type: SET_ACTIVE_PATTERN, value }),
    deleteActivePattern: (patternToDelete) => async ({dispatch, select}) => {

            if (!patternToDelete) {
				throw new Error('No pattern to delete.');
            }

            await deletePattern(patternToDelete);
			dispatch(actions.setActivePattern(null));
    },
    setEditorConfiguration: (value) => ({ type: SET_EDITOR_CONFIGURATION, value }),
    fetchEditorConfiguration: () => async ({dispatch}) => {
        try {
            const config = await fetchEditorConfiguration();
            dispatch(actions.setEditorConfiguration(config));
        } catch (error) {
            console.error('Failed to load editor configuration:', error);
        }
    },
    saveActivePattern: (updatedPattern) => async ({dispatch}) => {
        try {
            if (!updatedPattern) {
                console.warn('No pattern provided to save.');
                return;
            }

            const savedPattern = await savePattern(updatedPattern);
            dispatch(actions.setActivePattern(savedPattern));

			return savedPattern;

        } catch (error) {
            console.error('Failed to save the pattern:', error);
        }
    },
    fetchAllPatterns: () => async ({dispatch}) => {
        try {
            const patterns = await fetchAllPatterns();
            dispatch({ type: SET_ALL_PATTERNS, value: patterns });
        } catch (error) {
            console.error('Failed to fetch all patterns:', error);
        }
    },
};

// Selectors
const selectors = {
    getActivePattern: (state) => state.activePattern,
    getEditorConfiguration: (state) => state.editorConfiguration,
    getAllPatterns: (state) => state.allPatterns,
};

// Create and register the store
const store = createReduxStore('pattern-manager', {
    reducer,
    actions,
    selectors,
});

register(store);

export default store;
