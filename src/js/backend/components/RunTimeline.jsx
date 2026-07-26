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

// A run of this many consecutive same-reason "Skipped Media" lines collapses
// into a single expandable summary; shorter runs render in full.
const COLLAPSE_MIN = 3;

// Matches `Skipped Media "<name>": <reason>` (straight or curly quotes) and
// captures the reason — the varying name is ignored so lines that differ only
// by name group together.
const SKIP_RE = /^Skipped Media\s+["“][^"”]*["”]\s*:\s*(.+)$/;

/**
 * The trailing reason of a "Skipped Media" line, or null when the message is
 * not a media skip. Trailing period stripped for a cleaner summary.
 *
 * @param {string} message - Raw entry message.
 * @return {?string} Reason text, or null.
 */
const skipReason = (message) => {
	const match = decodeEntities(message).match(SKIP_RE);

	return match ? match[1].trim().replace(/\.$/, '') : null;
};

/**
 * Folds a flat entry list into display items: either a single `entry` or a
 * `group` of consecutive media-skip entries that share a reason and level.
 *
 * @param {Array} entries - Run entries, oldest first.
 * @return {Array} Display items ({kind:'entry'|'group', ...}).
 */
const groupEntries = (entries) => {
	const items = [];
	let i = 0;

	while (i < entries.length) {
		const entry = entries[i];
		const reason = skipReason(entry.message);

		if (reason !== null) {
			let j = i + 1;

			while (
				j < entries.length &&
				entries[j].level === entry.level &&
				skipReason(entries[j].message) === reason
			) {
				j++;
			}

			if (j - i >= COLLAPSE_MIN) {
				items.push({
					kind: 'group',
					key: `g${i}`,
					level: entry.level,
					reason,
					entries: entries.slice(i, j),
				});
				i = j;
				continue;
			}
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
 * Timeline for one run. Consecutive same-reason media-skip lines collapse into
 * an expandable summary ("255 media skipped — image import is turned off") to
 * keep long, repetitive logs readable. Each group tracks its own open state.
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

	const skippedLabel =
		sdEdiAdminParams.logGroupSkippedMedia || 'media skipped';

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
									{item.entries.length} {skippedLabel} —{' '}
									{item.reason}
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
