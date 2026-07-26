import React, { useMemo, useState } from 'react';
import { DownOutlined } from '@ant-design/icons';
import { decodeEntities } from '../utils/decodeEntities';
import { formatEntryTime } from '../utils/logFormat';

/* global sdEdiAdminParams */

/**
 * Per-level accent colours for the entry dots.
 */
const LEVEL_COLORS = {
	error: '#d63638',
	warning: '#dba617',
	success: '#00a32a',
	info: '#2d74d5',
	interrupted: '#e8830c',
};

// A run of this many consecutive equivalent lines collapses into a single
// expandable summary; shorter runs render in full.
const COLLAPSE_MIN = 3;

// Quoted item names (straight or curly quotes) are the part that varies between
// otherwise identical lines — "Failed to import Media “a.jpg”: …" and the same
// for "b.jpg". Blanking them is what lets those lines group together.
const QUOTED_RE = /["“][^"”]*["”]/g;

// The placeholder left behind, so a summary reads
// `Failed to import Media “…”: image import is turned off`.
const NAME_PLACEHOLDER = '“…”';

/**
 * Collapse key for an entry: its message with every quoted name blanked out.
 * Deliberately does not match on any English literal — log messages are
 * translated, so a pattern anchored to specific wording would silently stop
 * grouping on non-English sites.
 *
 * @param {string} message - Raw entry message.
 * @return {string} Normalized message.
 */
export const collapseKey = (message) =>
	decodeEntities(message).replace(QUOTED_RE, NAME_PLACEHOLDER);

/**
 * Folds a flat entry list into display items: either a single `entry` or a
 * `group` of consecutive entries that share a level and a collapse key.
 *
 * @param {Array} entries - Run entries, oldest first.
 * @return {Array} Display items ({kind:'entry'|'group', ...}).
 */
export const groupEntries = (entries) => {
	const items = [];
	let i = 0;

	while (i < entries.length) {
		const entry = entries[i];
		const summary = collapseKey(entry.message);
		let j = i + 1;

		while (
			j < entries.length &&
			entries[j].level === entry.level &&
			collapseKey(entries[j].message) === summary
		) {
			j++;
		}

		if (j - i >= COLLAPSE_MIN) {
			items.push({
				kind: 'group',
				key: `g${i}`,
				level: entry.level,
				summary,
				entries: entries.slice(i, j),
			});
			i = j;
			continue;
		}

		items.push({ kind: 'entry', key: `e${i}`, entry });
		i++;
	}

	return items;
};

/**
 * A single timeline row.
 *
 * @param {Object} entry - Log entry.
 * @return {JSX.Element} Row node.
 */
const entryRow = (entry) => (
	<>
		<span
			className="edi-log-entry-dot"
			style={{
				'--edi-dot': LEVEL_COLORS[entry.level] || LEVEL_COLORS.info,
			}}
		/>
		<time className="edi-log-entry-time" title={entry.logged_at}>
			{formatEntryTime(entry.logged_at)}
		</time>
		<span className="edi-log-entry-msg">
			{decodeEntities(entry.message)}
		</span>
	</>
);

/**
 * Timeline for one run. Consecutive lines that differ only by the item they
 * name collapse into an expandable summary ("255 similar entries — Failed to
 * import Media “…”: image import is turned off") to keep long, repetitive logs
 * readable. Each group tracks its own open state.
 *
 * @param {Object} props         - Component props.
 * @param {Array}  props.entries - Run entries, oldest first.
 * @return {JSX.Element} Timeline list.
 */
const RunTimeline = ({ entries }) => {
	const [open, setOpen] = useState(() => new Set());
	const items = useMemo(() => groupEntries(entries), [entries]);

	const toggle = (key) => {
		setOpen((prev) => {
			const next = new Set(prev);

			if (next.has(key)) {
				next.delete(key);
			} else {
				next.add(key);
			}

			return next;
		});
	};

	const groupedLabel = sdEdiAdminParams.logGroupRepeated || 'similar entries';

	return (
		<ul className="edi-log-timeline">
			{items.map((item) => {
				if (item.kind === 'entry') {
					return (
						<li
							key={item.key}
							className={`edi-log-entry level-${item.entry.level}`}
						>
							{entryRow(item.entry)}
						</li>
					);
				}

				const isOpen = open.has(item.key);

				return (
					<li
						key={item.key}
						className={`edi-log-entry edi-log-group level-${item.level}${
							isOpen ? ' is-open' : ''
						}`}
					>
						<span
							className="edi-log-entry-dot"
							style={{
								'--edi-dot':
									LEVEL_COLORS[item.level] ||
									LEVEL_COLORS.info,
							}}
						/>
						<time
							className="edi-log-entry-time"
							title={item.entries[0].logged_at}
						>
							{formatEntryTime(item.entries[0].logged_at)}
						</time>
						<div className="edi-log-group-body">
							<button
								type="button"
								className="edi-log-group-toggle"
								onClick={() => toggle(item.key)}
								aria-expanded={isOpen}
							>
								<span className="edi-log-group-summary">
									{item.entries.length} {groupedLabel} —{' '}
									{item.summary}
								</span>
								<span className="edi-log-group-toggle-label">
									{isOpen
										? sdEdiAdminParams.logGroupHide ||
											'Hide'
										: sdEdiAdminParams.logGroupShowAll ||
											'Show all'}
									<DownOutlined className="edi-log-group-caret" />
								</span>
							</button>
							{isOpen && (
								<ul className="edi-log-group-list">
									{item.entries.map((entry, index) => (
										<li
											key={index}
											className={`edi-log-group-item level-${entry.level}`}
										>
											<time
												className="edi-log-entry-time"
												title={entry.logged_at}
											>
												{formatEntryTime(
													entry.logged_at
												)}
											</time>
											<span className="edi-log-entry-msg">
												{decodeEntities(entry.message)}
											</span>
										</li>
									))}
								</ul>
							)}
						</div>
					</li>
				);
			})}
		</ul>
	);
};

export default RunTimeline;
