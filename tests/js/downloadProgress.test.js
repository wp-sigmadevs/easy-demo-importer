import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, doAxios } from '../../src/js/backend/utils/Api';
import Axios from 'axios';

/**
 * The demo download is the only phase whose progress cannot ride back on its
 * own response, so the modal polls a REST route while the request is in flight.
 * These cover that the poll runs for that phase only, feeds the bar, and is
 * always torn down — a leaked interval would keep polling for the whole import.
 */

const downloadRequest = {
	nextPhase: 'sd_edi_download_demo_files',
	demo: 'home-1',
	sessionId: 'session-1',
	reset: 'false',
	snapshot: 'false',
	excludeImages: 'false',
	skipImageRegeneration: 'false',
};

/**
 * Builds the argument list doAxios expects, with the percent setter captured.
 *
 * @param {Object} request          - Request payload.
 * @param {Object} setImportPercent - Spy for the percent setter.
 * @return {Array} doAxios arguments.
 */
const argsFor = (request, setImportPercent) => [
	request,
	vi.fn(),
	vi.fn(),
	vi.fn(),
	vi.fn(),
	vi.fn(),
	vi.fn(),
	setImportPercent,
];

describe('download progress polling', () => {
	let setImportPercent;

	beforeEach(() => {
		vi.useFakeTimers();
		setImportPercent = vi.fn();

		vi.spyOn(Axios, 'post').mockResolvedValue({
			status: 200,
			data: { error: false },
		});
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.restoreAllMocks();
	});

	it('polls immediately and feeds the percentage to the bar', async () => {
		vi.spyOn(Api, 'get').mockResolvedValue({
			data: {
				success: true,
				data: {
					tracking: true,
					downloaded: 25,
					total: 100,
					percent: 25,
				},
			},
		});

		await doAxios(...argsFor(downloadRequest, setImportPercent));

		expect(Api.get).toHaveBeenCalledWith('sd/edi/v1/download-progress', {
			params: { sessionId: 'session-1' },
		});
		expect(setImportPercent).toHaveBeenCalledWith(25);
	});

	it('leaves the bar alone when no total was advertised', async () => {
		// A chunked response yields a null percent; inventing one would be worse
		// than the indeterminate shimmer.
		vi.spyOn(Api, 'get').mockResolvedValue({
			data: {
				success: true,
				data: {
					tracking: true,
					downloaded: 4096,
					total: 0,
					percent: null,
				},
			},
		});

		await doAxios(...argsFor(downloadRequest, setImportPercent));

		expect(setImportPercent).not.toHaveBeenCalledWith(expect.any(Number));
	});

	it('does not poll for phases that report progress in their response', async () => {
		vi.spyOn(Api, 'get').mockResolvedValue({ data: { success: true } });

		await doAxios(
			...argsFor(
				{ ...downloadRequest, nextPhase: 'sd_edi_import_xml' },
				setImportPercent
			)
		);

		expect(Api.get).not.toHaveBeenCalled();
	});

	it('does not poll without a session id', async () => {
		vi.spyOn(Api, 'get').mockResolvedValue({ data: { success: true } });

		await doAxios(
			...argsFor({ ...downloadRequest, sessionId: '' }, setImportPercent)
		);

		expect(Api.get).not.toHaveBeenCalled();
	});

	it('stops polling once the request resolves', async () => {
		vi.spyOn(Api, 'get').mockResolvedValue({
			data: {
				success: true,
				data: { tracking: true, downloaded: 1, total: 10, percent: 10 },
			},
		});

		await doAxios(...argsFor(downloadRequest, setImportPercent));

		const callsAfterRequest = Api.get.mock.calls.length;

		await vi.advanceTimersByTimeAsync(5000);

		expect(Api.get.mock.calls.length).toBe(callsAfterRequest);
	});

	it('stops polling when the request fails', async () => {
		Axios.post.mockRejectedValue(new Error('network down'));

		vi.spyOn(Api, 'get').mockResolvedValue({
			data: {
				success: true,
				data: { tracking: true, downloaded: 1, total: 10, percent: 10 },
			},
		});

		await doAxios(...argsFor(downloadRequest, setImportPercent));

		const callsAfterRequest = Api.get.mock.calls.length;

		await vi.advanceTimersByTimeAsync(5000);

		expect(Api.get.mock.calls.length).toBe(callsAfterRequest);
	});

	it('keeps the import running when the poll itself fails', async () => {
		vi.spyOn(Api, 'get').mockRejectedValue(new Error('poll failed'));

		await expect(
			doAxios(...argsFor(downloadRequest, setImportPercent))
		).resolves.toBeUndefined();

		expect(Axios.post).toHaveBeenCalled();
	});
});
