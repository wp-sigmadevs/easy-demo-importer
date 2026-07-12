import React, { useState, useEffect } from 'react';
import { Collapse, Skeleton, Empty } from 'antd';
import ErrorMessage from './ErrorMessage';
import useSharedDataStore from '../utils/sharedDataStore';
import { decodeEntities } from '../utils/decodeEntities';

/* global sdEdiAdminParams */

/**
 * Per-level accent colours for entry dots and the run status pill.
 */
const LEVEL_COLORS = {
	error: '#d63638',
	warning: '#dba617',
	success: '#00a32a',
	info: '#2d74d5',
};

/**
 * Turns a raw demo slug ("home-01") into a display label ("Home-1"):
 * capitalizes the first letter and drops leading zeros from numeric segments.
 * Display only — the raw slug is still what's sent to the server/grouped by.
 *
 * @param {string} slug - Raw demo slug.
 * @return {string} Humanized label.
 */
const humanizeSlug = (slug) => {
	if (!slug) {
		return slug;
	}

	return slug
		.replace(/(^|-)0*(\d+)/g, (_match, sep, digits) => `${sep}${digits}`)
		.replace(/^./, (c) => c.toUpperCase());
};

/**
 * Parses a "YYYY-MM-DD HH:MM:SS" (UTC) stamp into a Date. Returns null for an
 * unexpected format rather than an Invalid Date, so callers can fall back to
 * the raw string.
 *
 * @param {string} stamp - Stored timestamp.
 * @return {?Date} Parsed date, or null.
 */
const parseStamp = (stamp) => {
	if (typeof stamp !== 'string') {
		return null;
	}

	const iso = stamp.includes('T') ? stamp : `${stamp.replace(' ', 'T')}Z`;
	const date = new Date(iso);

	return Number.isNaN(date.getTime()) ? null : date;
};

/**
 * Human-readable run start, e.g. "Jul 11, 2026, 10:56 AM". Falls back to the
 * raw stamp if it can't be parsed.
 *
 * @param {string} stamp - Run's started_at stamp.
 * @return {string} Formatted date/time.
 */
const formatRunTime = (stamp) => {
	const date = parseStamp(stamp);

	if (!date) {
		return stamp;
	}

	return date.toLocaleString(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	});
};

/**
 * Human-readable entry time, e.g. "10:56:01 AM". The run header already
 * carries the date, so entry rows stay to the clock only.
 *
 * @param {string} stamp - Entry's logged_at stamp.
 * @return {string} Formatted time.
 */
const formatEntryTime = (stamp) => {
	const date = parseStamp(stamp);

	if (!date) {
		return stamp;
	}

	return date.toLocaleTimeString(undefined, {
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
	});
};

/**
 * Header row for one import run.
 *
 * @param {Object} run - The run record.
 * @return {JSX.Element} Header node.
 */
const runLabel = (run) => {
	const statusText = {
		success: sdEdiAdminParams.logSuccess || 'Success',
		warning: sdEdiAdminParams.logWarning || 'Completed with warnings',
		error: sdEdiAdminParams.logFailed || 'Failed',
		info: sdEdiAdminParams.logInProgress || 'In progress',
	};

	const status = statusText[run.status] || run.status;

	return (
		<div className="edi-log-run" data-panel-key={run.session_id}>
			<span className="edi-log-run-name">
				{humanizeSlug(run.demo_slug) ||
					sdEdiAdminParams.logUnknownDemo ||
					'Import'}
			</span>
			<span className="edi-log-run-time">
				{formatRunTime(run.started_at)}
			</span>
			<span className={`edi-log-run-status is-${run.status}`}>
				{status}
			</span>
			<span className="edi-log-run-count">{run.count}</span>
		</div>
	);
};

/**
 * Timeline of entries for one run.
 *
 * @param {Array} entries - Log entries for the run.
 * @return {JSX.Element} Entry list.
 */
const runEntries = (entries) => (
	<ul className="edi-log-timeline">
		{entries.map((entry, index) => (
			<li key={index} className={`edi-log-entry level-${entry.level}`}>
				<span
					className="edi-log-entry-dot"
					style={{
						'--edi-dot':
							LEVEL_COLORS[entry.level] || LEVEL_COLORS.info,
					}}
				/>
				<time className="edi-log-entry-time" title={entry.logged_at}>
					{formatEntryTime(entry.logged_at)}
				</time>
				<span className="edi-log-entry-msg">
					{decodeEntities(entry.message)}
				</span>
			</li>
		))}
	</ul>
);

/**
 * The Import Log tab — all import runs, newest first, each expandable to its
 * timeline of entries. Fetches its own data and tracks its own loading state
 * (independent of the sibling System Status tab).
 */
const ImportLogPanel = () => {
	const [loading, setLoading] = useState(true);
	const [errorMessage, setErrorMessage] = useState('');
	const [activePanel, setActivePanel] = useState('');
	const { logData, fetchLogData } = useSharedDataStore();

	useEffect(() => {
		let ignore = false;

		(async () => {
			try {
				await fetchLogData('/sd/edi/v1/import/log?group=1');
			} catch (error) {
				// The store records an error shape; nothing to do here.
			} finally {
				if (!ignore) {
					setLoading(false);
				}
			}
		})();

		return () => {
			ignore = true;
		};
	}, [fetchLogData]);

	useEffect(() => {
		if (logData && logData.success === false) {
			setErrorMessage(logData.message);
		}
	}, [logData]);

	const runs = (logData && logData.success && logData.data) || [];

	// Open the most recent run by default, once runs are available.
	useEffect(() => {
		if (runs.length && !activePanel) {
			setActivePanel(runs[0].session_id);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [runs]);

	/**
	 * Handles the change of the active accordion panel, then smooth-scrolls
	 * to it once the expand animation has had time to run (matches
	 * ServerInfoCollapse's behavior for the System Status tab).
	 *
	 * @param {string} key - The key of the newly active panel.
	 */
	const handleAccordionChange = (key) => {
		setActivePanel(key);
		setTimeout(() => {
			scrollToPanel(key);
		}, 300);
	};

	/**
	 * Scrolls the page to the specified panel.
	 *
	 * @param {string} panelKey - The key of the panel to scroll to.
	 */
	const scrollToPanel = (panelKey) => {
		const panelElement = document.querySelector(
			`[data-panel-key="${panelKey}"]`
		);

		if (!panelElement) {
			return;
		}

		const offset = 140;
		const panelPosition = panelElement.getBoundingClientRect();
		const currentScrollY = window.scrollY;
		const scrollToPosition = panelPosition.top + currentScrollY - offset;

		window.scrollTo({
			top: scrollToPosition,
			behavior: 'smooth',
		});
	};

	const items = runs.map((run) => ({
		key: run.session_id,
		label: runLabel(run),
		children: runEntries(run.entries),
	}));

	let content;

	if (loading && !runs.length) {
		content = (
			<div className="skeleton-wrapper">
				{Array.from({ length: 4 }).map((_, i) => (
					<div className="list-skeleton details" key={i}>
						<Skeleton paragraph={{ rows: 1 }} active />
					</div>
				))}
			</div>
		);
	} else if (logData && logData.success === false) {
		content = <ErrorMessage message={errorMessage} />;
	} else if (!runs.length) {
		content = (
			<Empty
				description={
					sdEdiAdminParams.logEmpty ||
					'No import activity has been logged yet.'
				}
			/>
		);
	} else {
		content = (
			<Collapse
				className="edi-log-collapse edi-fade-in"
				bordered={false}
				accordion
				expandIconPosition="end"
				items={items}
				activeKey={activePanel}
				onChange={handleAccordionChange}
			/>
		);
	}

	return <div className="edi-log-panel">{content}</div>;
};

export default ImportLogPanel;
