import { describe, expect, it } from 'vitest';
import {
	collapseKey,
	groupEntries,
} from '../../src/js/backend/components/RunTimeline';

/**
 * Builds `count` warning entries that differ only by the quoted item name —
 * the exact shape class-wp-import.php emits for a failed attachment.
 *
 * @param {number} count  - How many entries.
 * @param {string} reason - Trailing reason, shared by all of them.
 * @return {Array} Entries.
 */
const failedMedia = (count, reason = 'image import is turned off') =>
	Array.from({ length: count }, (_, i) => ({
		level: 'warning',
		logged_at: '2026-07-26 10:00:00',
		message: `Failed to import Media &#8220;photo-${i}.jpg&#8221;: ${reason}`,
	}));

describe('collapseKey', () => {
	it('blanks the quoted name so lines differing only by it match', () => {
		const [a, b] = failedMedia(2);

		expect(collapseKey(a.message)).toBe(collapseKey(b.message));
	});

	it('decodes curly-quote entities before normalizing', () => {
		expect(collapseKey('Failed to import &#8220;a&#8221;: bad')).toBe(
			'Failed to import “…”: bad'
		);
	});

	it('treats straight and curly quotes alike', () => {
		expect(collapseKey('Skipped "a.png": bad')).toBe(
			collapseKey('Skipped “b.png”: bad')
		);
	});

	it('keeps differing reasons apart', () => {
		expect(collapseKey('Failed to import “a”: bad')).not.toBe(
			collapseKey('Failed to import “a”: worse')
		);
	});

	it('leaves an unquoted message untouched', () => {
		expect(collapseKey('Content imported.')).toBe('Content imported.');
	});
});

describe('groupEntries', () => {
	it('collapses a run of equivalent entries into one group', () => {
		const items = groupEntries(failedMedia(5));

		expect(items).toHaveLength(1);
		expect(items[0].kind).toBe('group');
		expect(items[0].entries).toHaveLength(5);
		expect(items[0].level).toBe('warning');
		expect(items[0].summary).toBe(
			'Failed to import Media “…”: image import is turned off'
		);
	});

	it('groups the real message shape emitted by the WXR importer', () => {
		// Regression: the original implementation matched `^Skipped Media "…"`,
		// a string no code path ever produced, so nothing ever collapsed.
		const items = groupEntries(failedMedia(3));

		expect(items[0].kind).toBe('group');
	});

	it('leaves a run shorter than the threshold expanded', () => {
		const items = groupEntries(failedMedia(2));

		expect(items).toHaveLength(2);
		expect(items.every((item) => 'entry' === item.kind)).toBe(true);
	});

	it('does not group across differing levels', () => {
		const entries = failedMedia(3);
		entries[1].level = 'error';

		expect(
			groupEntries(entries).every((item) => 'entry' === item.kind)
		).toBe(true);
	});

	it('does not group across differing reasons', () => {
		const entries = [
			...failedMedia(2),
			...failedMedia(2, 'download failed'),
		];

		expect(
			groupEntries(entries).every((item) => 'entry' === item.kind)
		).toBe(true);
	});

	it('keeps surrounding singles in order around a group', () => {
		const entries = [
			{ level: 'info', logged_at: 'a', message: 'Import started.' },
			...failedMedia(4),
			{ level: 'info', logged_at: 'b', message: 'Content imported.' },
		];

		const items = groupEntries(entries);

		expect(items.map((item) => item.kind)).toEqual([
			'entry',
			'group',
			'entry',
		]);
		expect(items[0].entry.message).toBe('Import started.');
		expect(items[2].entry.message).toBe('Content imported.');
	});

	it('groups failed-term lines, which name the taxonomy outside the quotes', () => {
		// `Failed to import %1$s "%2$s"` — the taxonomy stays, so lines from
		// different taxonomies must not merge.
		const term = (taxonomy, name) => ({
			level: 'warning',
			logged_at: '2026-07-26 10:00:00',
			message: `Failed to import ${taxonomy} &#8220;${name}&#8221;`,
		});

		const items = groupEntries([
			term('store_category', 'Electronics'),
			term('store_category', 'Fashion'),
			term('store_category', 'Vehicle'),
		]);

		expect(items).toHaveLength(1);
		expect(items[0].summary).toBe('Failed to import store_category “…”');

		expect(
			groupEntries([
				term('store_category', 'Electronics'),
				term('product_tag', 'Fashion'),
				term('store_category', 'Vehicle'),
			]).every((item) => 'entry' === item.kind)
		).toBe(true);
	});

	it('collapses repeated identical lines that carry no quoted name', () => {
		const entries = Array.from({ length: 3 }, () => ({
			level: 'warning',
			logged_at: '2026-07-26 10:00:00',
			message: 'Invalid post type rtrs.',
		}));

		expect(groupEntries(entries)[0].kind).toBe('group');
	});

	it('produces unique keys for adjacent items', () => {
		const items = groupEntries([
			...failedMedia(3),
			...failedMedia(3, 'download failed'),
		]);
		const keys = items.map((item) => item.key);

		expect(new Set(keys).size).toBe(keys.length);
	});

	it('handles an empty entry list', () => {
		expect(groupEntries([])).toEqual([]);
	});
});
