import { create } from 'zustand';
import { Api } from './Api';

export const usePluginListStore = create((set) => ({
	importList: {},
	pluginList: {},
	loading: true,
	fetchImportList: (endpoint) => {
		return Api.get(endpoint, {})
			.then((response) => {
				set({ importList: response.data, loading: false });
			})
			.catch((error) => {
				console.error(error);
			});
	},
	fetchPluginList: (endpoint) => {
		return Api.get(endpoint, {})
			.then((response) => {
				set({ pluginList: response.data });
			})
			.catch((error) => {
				console.error(error);
			});
	},
}));
