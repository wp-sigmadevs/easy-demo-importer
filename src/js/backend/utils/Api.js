/**
 * Create and configure an Api object with Axios
 */

import Axios from 'axios';

/* global sdEdiAdminParams */

/**
 * Axios instance for making API requests.
 *
 * @type {Object}
 */
export const Api = Axios.create({
	baseURL: sdEdiAdminParams.restApiUrl,
	headers: {
		'X-WP-Nonce': sdEdiAdminParams.restNonce,
	},
});

/**
 * Perform Axios request for import process.
 *
 * @param {Object}   request              - The import request data.
 * @param {Function} setImportProgress    - The function to set the import progress.
 * @param {Function} setCurrentStep       - The function to set the current step in the import process.
 * @param {Function} handleImportResponse - The function to handle the import response.
 * @param {Function} setMessage           - Set import message.
 */
export const doAxios = async (
	request,
	setImportProgress,
	setCurrentStep,
	handleImportResponse,
	setMessage
) => {
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

			if (!response.data.error) {
				handleImportResponse(response);

				if (response.data.nextPhaseMessage) {
					// Update import progress with next phase message
					setImportProgress((prevProgress) => [
						...prevProgress,
						{ message: response.data.nextPhaseMessage },
					]);

					// Replace the loading message with the completed message
					setImportProgress((prevProgress) =>
						prevProgress.map((progress, index) =>
							index === prevProgress.length - 2
								? {
										message: response.data.completedMessage,
										fade: true,
								  }
								: progress
						)
					);

					// Recursive call to continue the import process
					setTimeout(() => {
						if (response.data.nextPhase) {
							doAxios(
								response.data,
								setImportProgress,
								setCurrentStep,
								handleImportResponse,
								setMessage
							);
						} else {
							setCurrentStep(4);
						}
					}, 3000);
				} else {
					setCurrentStep(4);
				}
			} else {
				setMessage(response.data.errorMessage);
				setCurrentStep(4);
			}
		} catch (error) {
			setMessage(sdEdiAdminParams.importError);
		}
	} else {
		setMessage(sdEdiAdminParams.importSuccess);
	}
};
