/*
 * Create an Api object with Axios and
 * configure it for the WordPress Rest Api.
 */

/* global sdEdiAdminParams */

import Axios from 'axios';

export const Api = Axios.create({
	baseURL: sdEdiAdminParams.restApiUrl,
	headers: {
		'X-WP-Nonce': sdEdiAdminParams.restNonce,
	},
});

export const doAxios = async (request) => {
	if (request.nextPhase) {
		const params = new FormData();
		params.append('action', request.nextPhase);
		params.append('demo', request.demo);
		params.append('reset', request.reset);
		params.append('excludeImages', request.excludeImages);
		params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);

		const requestUrl = sdEdiAdminParams.ajaxUrl;

		try {
			const response = await Axios.post(requestUrl, params);
			// doAxios(response.data);

			if (!response.error) {
				console.log(response.data);
				setTimeout(() => {
					doAxios(response.data);
				}, 2000);
			} else {
				console.log(response.data.errorMessage);
			}
		} catch (error) {
			console.error('Error:', error);
		}
	} else {
		console.log(sdEdiAdminParams.importSuccess);
	}
};
