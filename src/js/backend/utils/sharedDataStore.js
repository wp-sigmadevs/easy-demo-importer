import { create } from 'zustand';
import { Api } from './Api';

/**
 * Shared data store for managing shared state and actions.
 *
 * @typedef  {Object}   SharedDataStore
 * @property {Object}   importList        - The import list data.
 * @property {Object}   pluginList        - The plugin list data.
 * @property {boolean}  loading           - The loading state.
 * @property {number}   currentStep       - The current step in the process.
 * @property {boolean}  modalVisible      - The visibility of the modal.
 * @property {boolean}  excludeImages     - The exclude images option.
 * @property {boolean}  reset             - The reset option.
 * @property {Function} fetchImportList   - Fetch the import list data.
 * @property {Function} fetchPluginList   - Fetch the plugin list data.
 * @property {Function} setCurrentStep    - Set the current step.
 * @property {Function} setModalVisible   - Set the visibility of the modal.
 * @property {Function} handleModalCancel - Handle the modal cancellation.
 * @property {Function} setExcludeImages  - Set the exclude images option.
 * @property {Function} setReset          - Set the reset option.
 * @property {Function} setLoading        - Set the loading state.
 */

/**
 * Create a shared data store with initial state and actions.
 *
 * @param {Function} set - The setter function.
 */
const useSharedDataStore = create((set) => ({
	importList: {},
	pluginList: {},
	loading: true,
	currentStep: 1,
	modalVisible: false,
	excludeImages: false,
	reset: true,
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
	setCurrentStep: (step) => set({ currentStep: step }),
	setModalVisible: (visible) => set({ modalVisible: visible }),
	handleModalCancel: () => set({ modalVisible: false }),
	setExcludeImages: (value) => set({ excludeImages: value }),
	setReset: (value) => set({ reset: value }),
	setLoading: (value) => set({ loading: value }),
}));

export default useSharedDataStore;
