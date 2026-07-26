import { describe, expect, it } from 'vitest';
import {
	MANUAL_SLUG,
	REGEN_SLUG,
	formatDuration,
	formatEntryTime,
	formatRunTime,
	humanizeSlug,
	parseStamp,
	runName,
	runStatusText,
} from '../../src/js/backend/utils/logFormat';

/**
 * Builds a run whose first entry is `started_at` and whose last entry lands
 * `seconds` later, which is how formatDuration derives elapsed time.
 *
 * @param {number} seconds - Span between the run start and its final entry.
 * @param {string} status  - Run status.
 * @return {Object} Run record.
 */
const runLasting = (seconds, status = 'success') => {
	const start = new Date('2026-07-26T10:00:00Z');
	const end = new Date(start.getTime() + seconds * 1000);
	const stamp = (date) => date.toISOString().slice(0, 19).replace('T', ' ');

	return {
		status,
		started_at: stamp(start),
		entries: [{ logged_at: stamp(start) }, { logged_at: stamp(end) }],
	};
};

describe('humanizeSlug', () => {
	it('capitalizes and strips leading zeros from numeric segments', () => {
		expect(humanizeSlug('home-01')).toBe('Home-1');
		expect(humanizeSlug('shop-007')).toBe('Shop-7');
	});

	it('keeps a zero that is the whole segment', () => {
		expect(humanizeSlug('demo-00')).toBe('Demo-0');
	});

	it('leaves digits that are not their own segment alone', () => {
		expect(humanizeSlug('shop2')).toBe('Shop2');
	});

	it('passes empty values straight through', () => {
		expect(humanizeSlug('')).toBe('');
		expect(humanizeSlug(undefined)).toBeUndefined();
	});
});

describe('runName', () => {
	it('uses the dedicated label for the regeneration pseudo-slug', () => {
		expect(runName({ demo_slug: REGEN_SLUG })).toBe(
			'Thumbnail Regeneration'
		);
	});

	it('uses the dedicated label for the manual-import pseudo-slug', () => {
		expect(runName({ demo_slug: MANUAL_SLUG })).toBe('Manual Import');
	});

	it('humanizes a real demo slug', () => {
		expect(runName({ demo_slug: 'home-02' })).toBe('Home-2');
	});

	it('falls back when the slug is missing', () => {
		expect(runName({ demo_slug: '' })).toBe('Import');
	});
});

describe('runStatusText', () => {
	it('maps every known status to a label', () => {
		expect(runStatusText({ status: 'success' })).toBe('Success');
		expect(runStatusText({ status: 'warning' })).toBe(
			'Completed with warnings'
		);
		expect(runStatusText({ status: 'error' })).toBe('Failed');
		expect(runStatusText({ status: 'info' })).toBe('In progress');
		expect(runStatusText({ status: 'interrupted' })).toBe('Interrupted');
	});

	it('echoes an unknown status rather than blanking it', () => {
		expect(runStatusText({ status: 'weird' })).toBe('weird');
	});
});

describe('parseStamp', () => {
	it('reads a stored MySQL stamp as UTC', () => {
		// ImportLogger writes current_time( 'mysql', true ) — GMT, no offset.
		expect(parseStamp('2026-07-26 10:30:00').toISOString()).toBe(
			'2026-07-26T10:30:00.000Z'
		);
	});

	it('accepts a stamp that already carries a T separator', () => {
		expect(parseStamp('2026-07-26T10:30:00Z').toISOString()).toBe(
			'2026-07-26T10:30:00.000Z'
		);
	});

	it('returns null instead of an Invalid Date', () => {
		expect(parseStamp('not a date')).toBeNull();
		expect(parseStamp(null)).toBeNull();
		expect(parseStamp(12345)).toBeNull();
	});
});

describe('formatRunTime / formatEntryTime', () => {
	it('falls back to the raw stamp when it cannot be parsed', () => {
		expect(formatRunTime('garbage')).toBe('garbage');
		expect(formatEntryTime('garbage')).toBe('garbage');
	});

	it('formats a parseable stamp into something other than the input', () => {
		const stamp = '2026-07-26 10:30:00';

		expect(formatRunTime(stamp)).not.toBe(stamp);
		expect(formatEntryTime(stamp)).not.toBe(stamp);
	});
});

describe('formatDuration', () => {
	it('reports sub-minute spans in seconds', () => {
		expect(formatDuration(runLasting(45))).toBe('45s');
	});

	it('reports minutes with and without a seconds remainder', () => {
		expect(formatDuration(runLasting(72))).toBe('1m 12s');
		expect(formatDuration(runLasting(120))).toBe('2m');
	});

	it('reports hours with and without a minutes remainder', () => {
		expect(formatDuration(runLasting(3780))).toBe('1h 3m');
		expect(formatDuration(runLasting(7200))).toBe('2h');
	});

	it('returns null for a run still in progress', () => {
		expect(formatDuration(runLasting(45, 'info'))).toBeNull();
	});

	it('returns null when there are no entries to finish on', () => {
		expect(
			formatDuration({
				status: 'success',
				started_at: '2026-07-26 10:00:00',
			})
		).toBeNull();
		expect(
			formatDuration({
				status: 'success',
				started_at: '2026-07-26 10:00:00',
				entries: [],
			})
		).toBeNull();
	});

	it('returns null rather than a negative span', () => {
		expect(formatDuration(runLasting(-30))).toBeNull();
	});

	it('returns null when a stamp is unparseable', () => {
		expect(
			formatDuration({
				status: 'success',
				started_at: 'garbage',
				entries: [{ logged_at: '2026-07-26 10:00:45' }],
			})
		).toBeNull();
	});
});
