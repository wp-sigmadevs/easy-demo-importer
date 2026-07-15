import { create } from 'zustand';
import { Api } from './Api';

/**
 * localStorage key for persisting the resume request across page reloads.
 */
const RESUME_KEY = 'sd_edi_resume_request';

const RESUME_TTL_MS = 30 * 60 * 1000; // 30 minutes — matches server-side mutex stale timeout

const loadResumeRequest = () => {
	try {
		const saved = localStorage.getItem(RESUME_KEY);

		if (!saved) {
			return null;
		}

		const data = JSON.parse(saved);

		if (data._expires && Date.now() > data._expires) {
			localStorage.removeItem(RESUME_KEY);
			return null;
		}

		return data;
	} catch {
		return null;
	}
};

const saveResumeRequest = (request) => {
	try {
		if (request) {
			localStorage.setItem(
				RESUME_KEY,
				JSON.stringify({
					...request,
					_expires: Date.now() + RESUME_TTL_MS,
				})
			);
		} else {
			localStorage.removeItem(RESUME_KEY);
		}
	} catch {
		// Ignore storage errors.
	}
};

/**
 * Shared data store for managing shared state and actions.
 *
 * @typedef  {Object}   SharedDataStore
 * @property {Object}   importList         - The import list data.
 * @property {Object}   pluginList         - The plugin list data.
 * @property {boolean}  loading            - The loading state.
 * @property {number}   currentStep        - The current step in the process.
 * @property {boolean}  modalVisible       - The visibility of the modal.
 * @property {boolean}  excludeImages      - The exclude images option.
 * @property {boolean}  importComplete     - The import completion status.
 * @property {boolean}  reset              - The reset option.
 * @property {string}   message            - The message.
 * @property {string}   activeSessionId    - The session ID of the currently running import.
 * @property {Function} fetchImportList    - Fetch the import list data.
 * @property {Function} fetchPluginList    - Fetch the plugin list data.
 * @property {Function} setCurrentStep     - Set the current step.
 * @property {Function} setModalVisible    - Set the visibility of the modal.
 * @property {Function} handleModalCancel  - Handle the modal cancellation.
 * @property {Function} setExcludeImages   - Set the exclude images option.
 * @property {Function} setReset           - Set the reset option.
 * @property {Function} setLoading         - Set the loading state.
 * @property {Function} setImportComplete  - Set the import completion status.
 * @property {Function} setMessage         - Set the message.
 * @property {Function} setActiveSessionId - Set the active session ID.
 */

/**
 * Create a shared data store with initial state and actions.
 *
 * @param {Function} set - The setter function.
 */
const useSharedDataStore = create((set) => ({
	importList: {},
	pluginList: {},
	serverData: {},
	logData: {},
	preflightData: {},
	loading: true,
	currentStep: 1,
	modalVisible: false,
	excludeImages: false,
	skipImageRegeneration: false,
	importComplete: false,
	reset: true,
	// On by default: reset is on by default and is destructive, so the restore
	// point (the parachute) should be armed too rather than left off.
	snapshot: true,
	message: '',
	hint: '',
	resumeRequest: loadResumeRequest(),
	activeSessionId: '',
	searchQuery: '',
	filteredDemoData: null,
	isSearchQueryEmpty: true,
	fetchImportList: async (endpoint) => {
		try {
			const response = await Api.get(endpoint, {});
			set({ importList: response.data, loading: false });
		} catch (error) {
			console.error(error);
		}
	},
	fetchPluginList: async (endpoint) => {
		try {
			const response = await Api.get(endpoint, {});
			set({ pluginList: response.data, loading: false });
		} catch (error) {
			console.error(error);
		}
	},
	fetchServerData: async (endpoint) => {
		try {
			const response = await Api.get(endpoint, {});
			set({ serverData: response.data, loading: false });
		} catch (error) {
			// Surface the failure so the panel can show an error state instead of
			// a misleading empty one.
			set({
				serverData: {
					success: false,
					message:
						error?.response?.data?.message ||
						'Could not load server status. Please refresh and try again.',
				},
				loading: false,
			});
		}
	},
	fetchPreflightData: async (endpoint) => {
		try {
			const response = await Api.get(endpoint, {});
			set({ preflightData: response.data });
		} catch (error) {
			// Never block the import on a failed readiness check — surface it as
			// an error entry but let the user proceed.
			set({
				preflightData: {
					success: false,
					message:
						error?.response?.data?.message ||
						'Could not run the readiness check.',
				},
			});
		}
	},
	fetchLogData: async (endpoint) => {
		try {
			const response = await Api.get(endpoint, {});
			set({ logData: response.data, loading: false });
		} catch (error) {
			// Surface the failure so the panel renders its error state rather than
			// the "no activity yet" empty state on a real transport error.
			set({
				logData: {
					success: false,
					message:
						error?.response?.data?.message ||
						'Could not load the import log. Please refresh and try again.',
				},
				loading: false,
			});
		}
	},
	setCurrentStep: (step) => set({ currentStep: step }),
	setModalVisible: (visible) => set({ modalVisible: visible }),
	handleModalCancel: () => set({ modalVisible: false }),
	setExcludeImages: (value) => set({ excludeImages: value }),
	setSkipImageRegeneration: (value) => set({ skipImageRegeneration: value }),
	setReset: (value) => set({ reset: value }),
	setSnapshot: (value) => set({ snapshot: value }),
	setLoading: (value) => set({ loading: value }),
	setImportComplete: (value) => set({ importComplete: value }),
	setMessage: (message) => set(() => ({ message })),
	setHint: (hint) => set(() => ({ hint })),
	setResumeRequest: (resumeRequest) => {
		saveResumeRequest(resumeRequest);
		set(() => ({ resumeRequest }));
	},
	setActiveSessionId: (activeSessionId) => set(() => ({ activeSessionId })),
	setSearchQuery: (query) => set({ searchQuery: query }),
	setFilteredDemoData: (data) => set({ filteredDemoData: data }),
	setIsSearchQueryEmpty: (value) => set({ isSearchQueryEmpty: value }),
	resetStore: () =>
		set({
			currentStep: 1,
			modalVisible: false,
			excludeImages: false,
			skipImageRegeneration: false,
			importComplete: false,
			reset: true,
			snapshot: true,
			message: '',
			hint: '',
			// resumeRequest is intentionally persisted across page reloads — do not clear it here.
			activeSessionId: '',
			searchQuery: '',
			filteredDemoData: null,
			isSearchQueryEmpty: true,
		}),
}));

export default useSharedDataStore;
