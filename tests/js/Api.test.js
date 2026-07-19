/**
 * Unit tests for doAxios — the client-driven import pipeline.
 *
 * The whole chunked/resumable import is orchestrated here: phase advancement,
 * transient auto-resume with backoff, the batch/regen retry loop, and the
 * capped mutex-wait poll. These are the parts a server can't test for us, so
 * they carry the most risk. Axios and timers are mocked; each behaviour is
 * driven through its callbacks.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Axios from 'axios';
import { doAxios } from '../../src/js/backend/utils/Api.js';

vi.mock('axios', () => {
	const post = vi.fn();
	const create = vi.fn(() => ({ post }));

	return { default: { create, post } };
});

/**
 * Builds a fresh set of callback spies and the positional argument list
 * doAxios expects after the request object.
 *
 * @return {Object} spies + args helper
 */
const makeCallbacks = () => {
	const spies = {
		setImportProgress: vi.fn(),
		setCurrentStep: vi.fn(),
		handleImportResponse: vi.fn(),
		setMessage: vi.fn(),
		setHint: vi.fn(),
		setResumeRequest: vi.fn(),
		setImportPercent: vi.fn(),
	};

	const args = (attempt = 0, mutexWait = 0) => [
		spies.setImportProgress,
		spies.setCurrentStep,
		spies.handleImportResponse,
		spies.setMessage,
		spies.setHint,
		spies.setResumeRequest,
		spies.setImportPercent,
		attempt,
		mutexWait,
	];

	return { spies, args };
};

describe('doAxios', () => {
	let cb;

	beforeEach(() => {
		cb = makeCallbacks();
		Axios.post.mockReset();
	});

	afterEach(() => {
		vi.useRealTimers();
	});

	it('reports success without hitting the server when there is no next phase', async () => {
		await doAxios({ nextPhase: '' }, ...cb.args());

		expect(Axios.post).not.toHaveBeenCalled();
		expect(cb.spies.setMessage).toHaveBeenCalledWith('Import complete!');
	});

	it('finishes the pipeline (step 5) when the server names no next phase', async () => {
		Axios.post.mockResolvedValue({ status: 200, data: { error: false } });

		await doAxios(
			{ nextPhase: 'sd_edi_import_widgets', demo: 'x' },
			...cb.args()
		);

		expect(cb.spies.handleImportResponse).toHaveBeenCalledOnce();
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('maps batch progress to a determinate percentage', async () => {
		Axios.post.mockResolvedValue({
			status: 200,
			data: { error: false, progress: { processed: 50, total: 200 } },
		});

		await doAxios(
			{ nextPhase: 'sd_edi_import_xml_batch', demo: 'x' },
			...cb.args()
		);

		expect(cb.spies.setImportPercent).toHaveBeenCalledWith(25);
	});

	it('surfaces a server-reported error and enables resume when a session exists', async () => {
		Axios.post.mockResolvedValue({
			status: 200,
			data: {
				error: true,
				errorMessage: 'Nonce verification failed.',
				errorHint: 'Reload the page.',
			},
		});

		const request = {
			nextPhase: 'sd_edi_import_xml_batch',
			demo: 'x',
			sessionId: 's1',
		};

		await doAxios(request, ...cb.args());

		expect(cb.spies.setMessage).toHaveBeenCalledWith(
			'Nonce verification failed.'
		);
		expect(cb.spies.setHint).toHaveBeenCalledWith('Reload the page.');
		expect(cb.spies.setResumeRequest).toHaveBeenCalledWith(request);
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('re-fires the next chunk on a plain retry, without counting it as a mutex wait', async () => {
		vi.useFakeTimers();
		Axios.post
			.mockResolvedValueOnce({
				status: 200,
				data: { error: false, retry: true, retryAfter: 0 },
			})
			.mockResolvedValueOnce({ status: 200, data: { error: false } });

		await doAxios(
			{ nextPhase: 'sd_edi_import_xml_batch', demo: 'x' },
			...cb.args()
		);
		await vi.runAllTimersAsync();

		expect(Axios.post).toHaveBeenCalledTimes(2);
		// No "waiting for another import" line should ever be added.
		const addedWaiting = cb.spies.setImportProgress.mock.calls.some(
			([updater]) =>
				typeof updater === 'function' &&
				JSON.stringify(updater([])).includes(
					'Waiting for another import'
				)
		);
		expect(addedWaiting).toBe(false);
	});

	it('polls while another import holds the lock, then gives up at the cap', async () => {
		Axios.post.mockResolvedValue({
			status: 200,
			data: { error: false, mutexHeld: true },
		});

		const request = {
			nextPhase: 'sd_edi_import_xml_batch',
			demo: 'x',
			sessionId: 's1',
		};

		// Enter one wait below the cap (24) so nextWait (25) exceeds it.
		await doAxios(request, ...cb.args(0, 24));

		expect(cb.spies.setMessage).toHaveBeenCalledWith(
			'Another import is still running.'
		);
		expect(cb.spies.setResumeRequest).toHaveBeenCalledWith(request);
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('keeps polling below the mutex-wait cap', async () => {
		vi.useFakeTimers();
		Axios.post
			.mockResolvedValueOnce({
				status: 200,
				data: { error: false, mutexHeld: true, retryAfter: 0 },
			})
			.mockResolvedValueOnce({ status: 200, data: { error: false } });

		await doAxios(
			{
				nextPhase: 'sd_edi_import_xml_batch',
				demo: 'x',
				sessionId: 's1',
			},
			...cb.args(0, 0)
		);
		await vi.runAllTimersAsync();

		expect(Axios.post).toHaveBeenCalledTimes(2);
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('advances to the next phase, resetting the bar to indeterminate', async () => {
		vi.useFakeTimers();
		Axios.post
			.mockResolvedValueOnce({
				status: 200,
				data: {
					error: false,
					nextPhase: 'sd_edi_import_widgets',
					nextPhaseMessage: 'Importing all widgets.',
					completedMessage: 'Settings imported.',
				},
			})
			.mockResolvedValueOnce({ status: 200, data: { error: false } });

		await doAxios(
			{ nextPhase: 'sd_edi_import_settings', demo: 'x' },
			...cb.args()
		);
		await vi.runAllTimersAsync();

		expect(cb.spies.setImportPercent).toHaveBeenCalledWith(null);
		expect(Axios.post).toHaveBeenCalledTimes(2);
	});

	it('auto-resumes an idempotent batch phase after a transient 503', async () => {
		vi.useFakeTimers();
		Axios.post
			.mockResolvedValueOnce({ status: 503, data: {} })
			.mockResolvedValueOnce({ status: 200, data: { error: false } });

		await doAxios(
			{
				nextPhase: 'sd_edi_import_xml_batch',
				demo: 'x',
				sessionId: 's1',
			},
			...cb.args()
		);
		await vi.runAllTimersAsync();

		expect(Axios.post).toHaveBeenCalledTimes(2);
	});

	it('does NOT auto-resume a non-idempotent phase; it surfaces the HTTP error', async () => {
		Axios.post.mockResolvedValue({ status: 500, data: {} });

		await doAxios(
			{ nextPhase: 'sd_edi_import_widgets', demo: 'x' },
			...cb.args()
		);

		expect(Axios.post).toHaveBeenCalledOnce();
		expect(cb.spies.setMessage).toHaveBeenCalledWith(
			expect.stringContaining('500')
		);
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('falls back to a generic message for an unmapped status code', async () => {
		Axios.post.mockResolvedValue({ status: 418, data: {} });

		await doAxios(
			{ nextPhase: 'sd_edi_import_widgets', demo: 'x' },
			...cb.args()
		);

		expect(cb.spies.setMessage).toHaveBeenCalledWith(
			expect.stringContaining('HTTP 418')
		);
	});

	it('reports a lost connection when the request rejects with no response', async () => {
		Axios.post.mockRejectedValue(new Error('network down'));

		await doAxios(
			{ nextPhase: 'sd_edi_import_widgets', demo: 'x', sessionId: 's1' },
			...cb.args()
		);

		expect(cb.spies.setMessage).toHaveBeenCalledWith(
			'Lost connection to the server.'
		);
		expect(cb.spies.setResumeRequest).toHaveBeenCalled();
		expect(cb.spies.setCurrentStep).toHaveBeenCalledWith(5);
	});

	it('prefers a specific error message from the PHP response body on rejection', async () => {
		Axios.post.mockRejectedValue({
			response: {
				status: 403,
				data: {
					data: {
						errorMessage: 'Session invalid.',
						errorHint: 'Log in again.',
					},
				},
			},
		});

		await doAxios(
			{ nextPhase: 'sd_edi_import_widgets', demo: 'x' },
			...cb.args()
		);

		expect(cb.spies.setMessage).toHaveBeenCalledWith('Session invalid.');
		expect(cb.spies.setHint).toHaveBeenCalledWith('Log in again.');
	});
});
