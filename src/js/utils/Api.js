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

export const doAxios = async (
	request,
	setImportProgress,
	setImportComplete,
	setCurrentStep
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

			if (!response.error) {
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
							// Move to the next phase
							doAxios(
								response.data,
								setImportProgress,
								setImportComplete,
								setCurrentStep
							);
						} else {
							// Import is complete
							setImportComplete(true);
							setCurrentStep(3); // Move to the final step
						}
					}, 3000);
				} else {
					// No next phase message, import is finished
					setImportComplete(true);
				}
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

