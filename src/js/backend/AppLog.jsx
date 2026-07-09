import Header from './Layouts/Header';
import { Row, Col, Skeleton, Collapse, Timeline, Tag, Empty } from 'antd';
import React, { useState, useEffect } from 'react';
import ErrorMessage from './components/ErrorMessage';
import useSharedDataStore from './utils/sharedDataStore';

/* global sdEdiAdminParams */

/**
 * Per-level dot colours for the entry timeline.
 */
const LEVEL_COLORS = {
	error: '#d63638',
	warning: '#dba617',
	success: '#00a32a',
	info: '#2271b1',
};

/**
 * antd Tag colour for a run's overall status.
 */
const STATUS_TAG = {
	error: 'error',
	success: 'success',
	info: 'processing',
};

/**
 * Builds the collapsible header for one import run.
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

	return (
		<span className="edi-log-run-label">
			<strong>
				{run.demo_slug || sdEdiAdminParams.logUnknownDemo || 'Import'}
			</strong>
			<span className="edi-log-run-time"> — {run.started_at} </span>
			<Tag color={STATUS_TAG[run.status] || 'default'}>
				{statusText[run.status] || run.status}
			</Tag>
			<span className="edi-log-run-count">{run.count}</span>
		</span>
	);
};

/**
 * The Import Log page — all import runs, newest first, each expandable to its
 * timeline of entries.
 */
const AppLog = () => {
	const [errorMessage, setErrorMessage] = useState('');
	const { logData, loading, fetchLogData } = useSharedDataStore();

	useEffect(() => {
		(async () => {
			try {
				await fetchLogData('/sd/edi/v1/import/log?group=1');
			} catch (error) {
				console.error(error);
			}
		})();
	}, [fetchLogData]);

	useEffect(() => {
		if (logData && logData.success === false) {
			setErrorMessage(logData.message);
		}
	}, [logData]);

	const runs = (logData && logData.success && logData.data) || [];

	const collapseItems = runs.map((run) => ({
		key: run.session_id,
		label: runLabel(run),
		children: (
			<Timeline
				items={run.entries.map((entry, index) => ({
					key: index,
					color: LEVEL_COLORS[entry.level] || LEVEL_COLORS.info,
					children: (
						<div className="edi-log-entry">
							<span className="edi-log-entry-time">
								{entry.logged_at}
							</span>
							<span className="edi-log-entry-message">
								{entry.message}
							</span>
						</div>
					),
				}))}
			/>
		),
	}));

	return (
		<div className="wrap edi-import-log-wrapper">
			<Header
				logo={sdEdiAdminParams.ediLogo}
				heading={sdEdiAdminParams.logPageHeading || 'Import Log'}
			/>

			<div className="edi-content">
				<div className="edi-container log-container">
					<Row gutter={[30, 30]}>
						<Col className="gutter-row" span={24}>
							{loading && !runs.length ? (
								<div className="skeleton-wrapper">
									{Array.from({ length: 4 }).map((_, i) => (
										<div
											className="list-skeleton details"
											key={i}
										>
											<Skeleton
												paragraph={{ rows: 1 }}
												active
											/>
										</div>
									))}
								</div>
							) : logData && logData.success === false ? (
								<ErrorMessage message={errorMessage} />
							) : runs.length ? (
								<Collapse
									className="edi-log-collapse edi-fade-in"
									items={collapseItems}
									defaultActiveKey={
										runs.length ? [runs[0].session_id] : []
									}
								/>
							) : (
								<Empty
									description={
										sdEdiAdminParams.logEmpty ||
										'No import activity has been logged yet.'
									}
								/>
							)}
						</Col>
					</Row>
				</div>
			</div>
		</div>
	);
};

export default AppLog;
