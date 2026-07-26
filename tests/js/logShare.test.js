import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	buildReport,
	copyText,
	downloadText,
	runFileName,
} from '../../src/js/backend/utils/logShare';

const run = {
	session_id: 'abc-123',
	demo_slug: 'home-01',
	status: 'warning',
	started_at: '2026-07-26 10:00:00',
	count: 2,
	entries: [
		{
			logged_at: '2026-07-26 10:00:00',
			level: 'info',
			message: 'Started.',
		},
		{
			logged_at: '2026-07-26 10:00:45',
			level: 'warning',
			message: 'Failed to import &#8220;Schema for Posts&#8221;.',
		},
	],
};

const serverInfo = {
	environment: {
		label: 'Environment',
		fields: {
			php: { label: 'PHP Version', value: '7.4.33' },
		},
	},
	copy_system_data: { label: 'Copy System Data', fields: { a: {} } },
	inactive_plugins: { label: 'Inactive Plugins', fields: { b: {} } },
};

describe('buildReport', () => {
	it('includes the title, runs heading and one block per run', () => {
		const report = buildReport([run], null);

		expect(report).toContain('=== Easy Demo Importer — Import Log ===');
		expect(report).toContain('== Import Runs ==');
		expect(report).toContain(
			'--- Home-1 · Completed with warnings · 45s ---'
		);
		expect(report).toContain('Entries: 2');
	});

	it('decodes entities in entry messages and pads the level column', () => {
		const report = buildReport([run], null);

		expect(report).toContain('Failed to import “Schema for Posts”.');
		expect(report).toContain('INFO        Started.');
		expect(report).toMatch(/WARNING {5}Failed to import/);
	});

	it('includes environment sections but skips the noisy ones', () => {
		const report = buildReport([run], serverInfo);

		expect(report).toContain('== Environment ==');
		expect(report).toContain('PHP Version: 7.4.33');
		expect(report).not.toContain('Copy System Data');
		expect(report).not.toContain('Inactive Plugins');
	});

	it('degrades to runs-only when server info is unavailable', () => {
		expect(buildReport([run], null)).not.toContain('== Environment ==');
		expect(buildReport([run], 'nonsense')).not.toContain(
			'== Environment =='
		);
	});

	it('ends with exactly one trailing newline', () => {
		expect(buildReport([run], serverInfo)).toMatch(/[^\n]\n$/);
	});

	it('falls back to the run start when a run has no entries', () => {
		const report = buildReport([{ ...run, entries: [], count: 0 }], null);

		expect(report).toContain('Started: ');
		expect(report).toContain('Finished: ');
	});
});

describe('runFileName', () => {
	it('slugifies the run name', () => {
		expect(runFileName(run)).toBe('edi-import-log-home-1.txt');
	});

	it('falls back to the session id when the name slugifies to nothing', () => {
		expect(runFileName({ ...run, demo_slug: '—' })).toBe(
			'edi-import-log-abc-123.txt'
		);
	});

	it('never emits path separators', () => {
		expect(runFileName({ ...run, demo_slug: 'a/../../b' })).not.toContain(
			'/'
		);
	});
});

describe('copyText', () => {
	afterEach(() => {
		vi.unstubAllGlobals();
		delete navigator.clipboard;
	});

	it('uses the async clipboard API in a secure context', async () => {
		const writeText = vi.fn().mockResolvedValue();

		vi.stubGlobal('isSecureContext', true);
		navigator.clipboard = { writeText };

		await expect(copyText('hello')).resolves.toBe(true);
		expect(writeText).toHaveBeenCalledWith('hello');
	});

	it('falls back to execCommand when the clipboard API rejects', async () => {
		vi.stubGlobal('isSecureContext', true);
		navigator.clipboard = {
			writeText: vi.fn().mockRejectedValue(new Error('denied')),
		};
		document.execCommand = vi.fn().mockReturnValue(true);

		await expect(copyText('hello')).resolves.toBe(true);
		expect(document.execCommand).toHaveBeenCalledWith('copy');
	});

	it('cleans up its textarea even when the fallback throws', async () => {
		vi.stubGlobal('isSecureContext', false);
		document.execCommand = vi.fn(() => {
			throw new Error('nope');
		});

		await expect(copyText('hello')).resolves.toBe(false);
		expect(document.querySelector('textarea')).toBeNull();
	});
});

describe('downloadText', () => {
	beforeEach(() => {
		URL.createObjectURL = vi.fn(() => 'blob:fake');
		URL.revokeObjectURL = vi.fn();
	});

	it('clicks a download link and releases the blob url', () => {
		const click = vi.fn();
		const create = document.createElement.bind(document);

		vi.spyOn(document, 'createElement').mockImplementation((tag) => {
			const node = create(tag);

			if ('a' === tag) {
				node.click = click;
			}

			return node;
		});

		downloadText('log.txt', 'contents');

		expect(click).toHaveBeenCalled();
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake');
		expect(document.querySelector('a')).toBeNull();

		document.createElement.mockRestore();
	});
});
