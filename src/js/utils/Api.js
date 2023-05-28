/*
 * Create an Api object with Axios and
 * configure it for the WordPress Rest Api.
 */

/* global sdEdiAdminParams */

import Axios from 'axios';

export const Api = Axios.create({
	baseURL: sdEdiAdminParams.restApiUrl,
	headers: {
		'X-WP-Nonce': sdEdiAdminParams.restNonce
	}
});
