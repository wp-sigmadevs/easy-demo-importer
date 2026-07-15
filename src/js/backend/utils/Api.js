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
 * @return {{ message: string, hint: string }}
 */
const httpErrorInfo = (code) => {
	const map = {
		401: {
			message:
				'The request was rejected by the server (401 Unauthorized).',
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
			message:
				'The server took too long to respond (408 Request Timeout).',
			hint: 'Your server may be under load or PHP max_execution_time is too low. Try again, or ask your host to increase the execution time limit.',
		},
		429: {
			message: 'Too many requests were sent (429 Too Many Requests).',
			hint: 'Wait a few minutes before trying again.',
		},
		500: {
			message:
				'The server encountered an internal error (500 Internal Server Error).',
			hint: 'Check your PHP error log for details. This is usually a server configuration issue unrelated to the import.',
		},
		502: {
			message: 'The server gateway returned an error (502 Bad Gateway).',
			hint: 'A proxy in front of your site (often Cloudflare or a load balancer) could not reach the server. Try again; if it persists, ask your host to check the upstream.',
		},
		503: {
			message:
				'The server is temporarily unavailable (503 Service Unavailable).',
			hint: 'The server may be under maintenance. Wait a few minutes and try again.',
		},
		504: {
			message: 'The server gateway timed out (504 Gateway Timeout).',
			hint: 'Your server or the remote server is temporarily overloaded. Try again in a few minutes. If the problem persists, contact your host about PHP execution time limits.',
		},
		520: {
			message: 'Cloudflare reported an unknown server error (520).',
			hint: 'This is a Cloudflare edge error, usually from the origin closing the connection during a long import. The import resumes automatically; if it stops, run it again — it continues where it left off.',
		},
		522: {
			message: 'The connection to the server timed out (Cloudflare 522).',
			hint: 'Cloudflare could not get a timely response from your origin. Large imports are chunked to avoid this — retry to resume; consider asking your host to raise the origin timeout.',
		},
		524: {
			message: 'The server took too long to respond (Cloudflare 524).',
			hint: 'Cloudflare enforces a fixed ~100s limit per request. The import is split into small resumable steps to stay under it — retry to continue from the last completed step.',
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
 * Import phases that are safe to auto-resume after a transient failure.
 * The batch stage is idempotent server-side (cursor + mutex + post_exists),
 * so re-issuing the same request simply continues where it left off.
 *
 * @type {string[]}
 */
const AUTO_RESUME_PHASES = [
	'sd_edi_import_xml_batch',
	'sd_edi_regenerate_images',
];

/**
 * Phases whose card shows a progress bar. Only the content import and image
 * regeneration report real quantitative progress; every other phase (plugins,
 * customizer, widgets, …) is effectively instant and shows a plain card.
 * `sd_edi_import_xml` is the content phase's entry action (its card persists
 * across the internal prepare → batch → finalize sub-phases).
 *
 * @type {string[]}
 */
const PROGRESS_BAR_PHASES = ['sd_edi_import_xml', 'sd_edi_regenerate_images'];

/**
 * Maximum consecutive automatic resume attempts before surfacing the manual
 * Resume screen.
 *
 * @type {number}
 */
const MAX_AUTO_RESUME = 5;

/**
 * HTTP statuses treated as transient (gateway/server overload or timeout),
 * including Cloudflare's 52x family. A dropped connection (no response) is
 * treated the same way.
 *
 * @type {number[]}
 */
const TRANSIENT_STATUSES = [408, 429, 500, 502, 503, 504, 520, 522, 524];

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
 * @param {Function} setImportPercent     - Set the determinate progress percentage (0-100), or null.
 * @param {number}   attempt              - Current auto-resume attempt (internal; starts at 0).
 */
export const doAxios = async (
	request,
	setImportProgress,
	setCurrentStep,
	handleImportResponse,
	setMessage,
	setHint = () => {},
	setResumeRequest = () => {},
	setImportPercent = () => {},
	attempt = 0
) => {
	if (request.nextPhase) {
		const params = new FormData();

		params.append('action', request.nextPhase);
		params.append('demo', request.demo);
		params.append('reset', request.reset);
		params.append('snapshot', request.snapshot);
		params.append('manual', request.manual || 'false');
		params.append('manualKey', request.manualKey || '');
		params.append('excludeImages', request.excludeImages);
		params.append('skipImageRegeneration', request.skipImageRegeneration);
		params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);

		if (request.sessionId) {
			params.append('sessionId', request.sessionId);
			// Checkpoint before the call — if the page reloads mid-step, Resume can pick up here.
			setResumeRequest(request);
		}

		const requestUrl = sdEdiAdminParams.ajaxUrl;

		// Re-issues the current request after a transient failure, with capped
		// exponential backoff. Only used for idempotent phases (see caller gate).
		const scheduleAutoResume = () => {
			const nextAttempt = attempt + 1;
			const delaySec = Math.min(30, 2 ** attempt); // 1, 2, 4, 8, 16, 30…

			setImportProgress((prevProgress) => [
				...prevProgress,
				{
					message: `Connection interrupted — resuming automatically (attempt ${nextAttempt}/${MAX_AUTO_RESUME})…`,
					fade: true,
				},
			]);

			setTimeout(() => {
				doAxios(
					request,
					setImportProgress,
					setCurrentStep,
					handleImportResponse,
					setMessage,
					setHint,
					setResumeRequest,
					setImportPercent,
					nextAttempt
				);
			}, delaySec * 1000);
		};

		// Whether a failure on this request should be auto-resumed rather than
		// surfaced immediately. Gated to the idempotent batch phase + an active
		// session, capped at MAX_AUTO_RESUME.
		const canAutoResume = (status) =>
			AUTO_RESUME_PHASES.includes(request.nextPhase) &&
			request.sessionId &&
			attempt < MAX_AUTO_RESUME &&
			(status === 0 || TRANSIENT_STATUSES.includes(status));

		try {
			const response = await Axios.post(requestUrl, params);

			if (response.status === 200) {
				if (!response.data.error) {
					// Update the determinate progress bar whenever the server
					// reports batch progress (0-100).
					if (
						response.data.progress &&
						response.data.progress.total > 0
					) {
						const { processed, total } = response.data.progress;
						setImportPercent(
							Math.min(100, Math.round((processed / total) * 100))
						);
					}

					// retry:true means the PHP mutex was held by a background import.
					// Re-send the same original request after retryAfter seconds — do NOT
					// advance the pipeline or touch the progress list.
					if (response.data.retry) {
						setTimeout(
							() => {
								doAxios(
									request,
									setImportProgress,
									setCurrentStep,
									handleImportResponse,
									setMessage,
									setHint,
									setResumeRequest,
									setImportPercent
								);
							},
							(response.data.retryAfter ?? 5) * 1000
						);
						return;
					}

					handleImportResponse(response);

					// Advance whenever the server names a next phase — even if it
					// did not include a progress message. Gating advancement on the
					// message (the previous behavior) silently halted the pipeline
					// at the result screen whenever a phase legitimately skipped its
					// work and returned an empty nextPhaseMessage.
					if (response.data.nextPhase) {
						// A non-empty nextPhaseMessage marks a new user-facing card.
						// Internal sub-phases (empty message — e.g. content import's
						// prepare → batch → finalize) intentionally add no card and
						// leave the bar untouched, so the single content card's bar
						// advances smoothly instead of flickering back to 0 at each
						// hand-off. Only a genuinely new card resets the bar to its
						// indeterminate (shimmer) state until its own data arrives.
						if (response.data.nextPhaseMessage) {
							setImportPercent(null);

							// Add the new phase's card, then fade the previous card
							// to its completed state. Some transitions don't supply a
							// completedMessage — keep the outgoing card's own text in
							// that case instead of blanking it.
							setImportProgress((prevProgress) => [
								...prevProgress,
								{
									message: response.data.nextPhaseMessage,
									showBar: PROGRESS_BAR_PHASES.includes(
										response.data.nextPhase
									),
								},
							]);

							setImportProgress((prevProgress) =>
								prevProgress.map((progress, index) =>
									index === prevProgress.length - 2
										? {
												...progress,
												message:
													response.data
														.completedMessage ||
													progress.message,
												fade: true,
											}
										: progress
								)
							);
						}

						// Continue the import process.
						setTimeout(() => {
							doAxios(
								response.data,
								setImportProgress,
								setCurrentStep,
								handleImportResponse,
								setMessage,
								setHint,
								setResumeRequest,
								setImportPercent
							);
						}, 3000);
					} else {
						setCurrentStep(5);
					}
				} else {
					setMessage(
						response.data.errorMessage ||
							'An error occurred during import.'
					);
					setHint(response.data.errorHint || '');
					// Only allow resume if a session was already started (i.e. not a lock-conflict on Initialize).
					if (request.sessionId) {
						setResumeRequest(request);
					}
					setCurrentStep(5);
				}
			} else {
				if (canAutoResume(response.status)) {
					scheduleAutoResume();
					return;
				}

				const { message, hint } = httpErrorInfo(response.status);
				setMessage(message);
				setHint(hint);
				if (request.sessionId) {
					setResumeRequest(request);
				}
				setCurrentStep(5);
			}
		} catch (error) {
			const status = error.response ? error.response.status : 0;
			if (canAutoResume(status)) {
				scheduleAutoResume();
				return;
			}

			// error.response is undefined on network timeouts or dropped connections.
			if (error.response) {
				// Prefer specific messages from the PHP response body (e.g. nonce failure, session invalid).
				const data = error.response.data || {};
				if (data.data && data.data.errorMessage) {
					setMessage(data.data.errorMessage);
					setHint(data.data.errorHint || '');
				} else {
					const { message, hint } = httpErrorInfo(
						error.response.status
					);
					setMessage(message);
					setHint(hint);
				}
			} else {
				setMessage('Lost connection to the server.');
				setHint(
					'Check your internet connection. If you are on a local server, ensure it is running correctly. Try refreshing the page.'
				);
			}
			if (request.sessionId) {
				setResumeRequest(request);
			}
			setCurrentStep(5);
		}
	} else {
		setMessage(sdEdiAdminParams.importSuccess);
	}
};
