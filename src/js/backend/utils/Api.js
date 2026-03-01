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
 * Map an HTTP status code to a user-facing message and hint.
 *
 * @param {number} code - HTTP status code.
 * @returns {{ message: string, hint: string }}
 */
const httpErrorInfo = (code) => {
	const map = {
		401: {
			message: 'The request was rejected by the server (401 Unauthorized).',
			hint: 'Your WordPress admin session may have expired. Log out and back in, then try again.',
		},
		403: {
			message: 'The request was blocked by the server (403 Forbidden).',
			hint: 'Your admin session may have expired. Try logging out and back in. If the problem persists, a security plugin may be blocking the request.',
		},
		404: {
			message: 'The requested resource was not found (404 Not Found).',
			hint: 'The demo configuration may point to a missing file. Contact theme support.',
		},
		408: {
			message: 'The server took too long to respond (408 Request Timeout).',
			hint: 'Your server may be under load or PHP max_execution_time is too low. Try again, or ask your host to increase the execution time limit.',
		},
		429: {
			message: 'Too many requests were sent (429 Too Many Requests).',
			hint: 'Wait a few minutes before trying again.',
		},
		500: {
			message: 'The server encountered an internal error (500 Internal Server Error).',
			hint: 'Check your PHP error log for details. This is usually a server configuration issue unrelated to the import.',
		},
		503: {
			message: 'The server is temporarily unavailable (503 Service Unavailable).',
			hint: 'The server may be under maintenance. Wait a few minutes and try again.',
		},
		504: {
			message: 'The server gateway timed out (504 Gateway Timeout).',
			hint: 'Your server or the remote server is temporarily overloaded. Try again in a few minutes. If the problem persists, contact your host about PHP execution time limits.',
		},
	};

	if (map[code]) {
		return map[code];
	}

	return {
		message: `An unexpected server error occurred (HTTP ${code}).`,
		hint: 'Try refreshing the page. If the problem persists, contact theme support with this error code.',
	};
};

/**
 * Perform Axios request for import process.
 *
 * @param {Object}   request              - The import request data.
 * @param {Function} setImportProgress    - Set the import progress.
 * @param {Function} setCurrentStep       - Set current step in import process.
 * @param {Function} handleImportResponse - Handle the import response.
 * @param {Function} setMessage           - Set import message.
 * @param {Function} setHint              - Set import error hint.
 * @param {Function} setResumeRequest     - Save the failed request so Resume can retry it.
 */
export const doAxios = async (
	request,
	setImportProgress,
	setCurrentStep,
	handleImportResponse,
	setMessage,
	setHint = () => {},
	setResumeRequest = () => {}
) => {
	if (request.nextPhase) {
		const params = new FormData();

		params.append('action', request.nextPhase);
		params.append('demo', request.demo);
		params.append('reset', request.reset);
		params.append('excludeImages', request.excludeImages);
		params.append('skipImageRegeneration', request.skipImageRegeneration);
		params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);

		if (request.sessionId) {
			params.append('sessionId', request.sessionId);
			// Checkpoint before the call — if the page reloads mid-step, Resume can pick up here.
			setResumeRequest(request);
		}

		const requestUrl = sdEdiAdminParams.ajaxUrl;

		try {
			const response = await Axios.post(requestUrl, params);

			if (response.status === 200) {
				if (!response.data.error) {
					// retry:true means the PHP mutex was held by a background import.
					// Re-send the same original request after retryAfter seconds — do NOT
					// advance the pipeline or touch the progress list.
					if (response.data.retry) {
						setTimeout(() => {
							doAxios(
								request,
								setImportProgress,
								setCurrentStep,
								handleImportResponse,
								setMessage,
								setHint,
								setResumeRequest
							);
						}, (response.data.retryAfter || 5) * 1000);
						return;
					}

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
											message:
												response.data.completedMessage,
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
									setMessage,
									setHint,
									setResumeRequest
								);
							} else {
								setCurrentStep(4);
							}
						}, 3000);
					} else {
						setCurrentStep(4);
					}
				} else {
					setMessage(response.data.errorMessage || 'An error occurred during import.');
					setHint(response.data.errorHint || '');
					// Only allow resume if a session was already started (i.e. not a lock-conflict on Initialize).
					if (request.sessionId) { setResumeRequest(request); }
					setCurrentStep(4);
				}
			} else {
				const { message, hint } = httpErrorInfo(response.status);
				setMessage(message);
				setHint(hint);
				if (request.sessionId) { setResumeRequest(request); }
				setCurrentStep(4);
			}
		} catch (error) {
			// error.response is undefined on network timeouts or dropped connections.
			if (error.response) {
				// Prefer specific messages from the PHP response body (e.g. nonce failure, session invalid).
				const data = error.response.data || {};
				if (data.data && data.data.errorMessage) {
					setMessage(data.data.errorMessage);
					setHint(data.data.errorHint || '');
				} else {
					const { message, hint } = httpErrorInfo(error.response.status);
					setMessage(message);
					setHint(hint);
				}
			} else {
				setMessage('Lost connection to the server.');
				setHint('Check your internet connection. If you are on a local server, ensure it is running correctly. Try refreshing the page.');
			}
			if (request.sessionId) { setResumeRequest(request); }
			setCurrentStep(4);
		}
	} else {
		setMessage(sdEdiAdminParams.importSuccess);
	}
};
