import { create } from 'zustand';
import { Api } from './Api';

export const useImportListStore = create((set) => ({
	importList: {},
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
}));
