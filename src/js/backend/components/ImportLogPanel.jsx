import React, { useState, useEffect } from 'react';
import { Collapse, Skeleton, Empty } from 'antd';
import ErrorMessage from './ErrorMessage';
import useSharedDataStore from '../utils/sharedDataStore';

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
 * Extracts the clock portion (HH:MM:SS) from a "YYYY-MM-DD HH:MM:SS" stamp so
 * per-entry rows stay compact — the run header already carries the date. Falls
 * back to the whole string if the format is unexpected.
 *
 * @param {string} stamp - Full timestamp.
 * @return {string} Time portion.
 */
const timeOnly = (stamp) =>
	typeof stamp === 'string' && stamp.includes(' ')
		? stamp.split(' ').pop()
		: stamp;

/**
 * Header row for one import run.
 *
 * @param {Object} run - The run record.
 * @return {JSX.Element} Header node.
 */
const runLabel = (run) => {
	const statusText = {
		success: sdEdiAdminParams.logSuccess || 'Success',
		error: sdEdiAdminParams.logFailed || 'Failed',
		info: sdEdiAdminParams.logInProgress || 'In progress',
	};

	const status = statusText[run.status] || run.status;

	return (
		<div className="edi-log-run">
			<span className="edi-log-run-name">
				{run.demo_slug || sdEdiAdminParams.logUnknownDemo || 'Import'}
			</span>
			<span className="edi-log-run-time">{run.started_at}</span>
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
					{timeOnly(entry.logged_at)}
				</time>
				<span className="edi-log-entry-msg">{entry.message}</span>
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
	const { logData, fetchLogData } = useSharedDataStore();

	useEffect(() => {
		(async () => {
			try {
				await fetchLogData('/sd/edi/v1/import/log?group=1');
			} catch (error) {
				console.error(error);
			} finally {
				setLoading(false);
			}
		})();
	}, [fetchLogData]);

	useEffect(() => {
		if (logData && logData.success === false) {
			setErrorMessage(logData.message);
		}
	}, [logData]);

	const runs = (logData && logData.success && logData.data) || [];

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
				expandIconPosition="end"
				items={items}
				defaultActiveKey={[runs[0].session_id]}
			/>
		);
	}

	return <div className="edi-log-panel">{content}</div>;
};

export default ImportLogPanel;
