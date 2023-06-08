import { create } from 'zustand';
import { Api } from './Api';

const useSharedDataStore = create((set) => ({
	importList: {},
	pluginList: {},
	loading: true,
	currentStep: 1,
	modalVisible: false,
	excludeImages: false,
	reset: true,

	// fetchImportList: (endpoint) => {
	// 	return Api.get(endpoint, {})
	// 		.then((response) => {
	// 			set({ importList: response.data, loading: false });
	// 		})
	// 		.catch((error) => {
	// 			console.error(error);
	// 		});
	// },

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

	// fetchPluginList: (endpoint) => {
	// 	return Api.get(endpoint, {})
	// 		.then((response) => {
	// 			set({ pluginList: response.data, loading: false });
	// 		})
	// 		.catch((error) => {
	// 			console.error(error);
	// 		});
	// },

	setCurrentStep: (step) => set({ currentStep: step }),

	setModalVisible: (visible) => set({ modalVisible: visible }),

	handleModalCancel: () => set({ modalVisible: false }),

	setExcludeImages: (value) => set({ excludeImages: value }),

	setReset: (value) => set({ reset: value }),

	setLoading: (value) => set({ loading: value }),
}));

export default useSharedDataStore;
