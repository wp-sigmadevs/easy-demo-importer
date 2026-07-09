import React, { useState, useEffect } from 'react';
import { Result, Button, Collapse } from 'antd';
import {
	ExportOutlined,
	CloseOutlined,
	RedoOutlined,
	RollbackOutlined,
} from '@ant-design/icons';
import { Api } from '../../../utils/Api';

/* global sdEdiAdminParams */

/**
 * Per-level dot colours for inline log lines.
 */
const LEVEL_COLORS = {
	error: '#d63638',
	warning: '#dba617',
	success: '#00a32a',
	info: '#2271b1',
};

/**
 * Component representing the import success or failure message.
 *
 * @param {boolean}  importComplete  - Flag indicating whether the import was completed.
 * @param {Function} handleReset     - Function to handle the reset action.
 * @param {Function} handleResume    - Function to resume the import from the failed step.
 * @param {Function} handleStartOver - Function to release the lock and reload.
 * @param {boolean}  canResume       - Whether a resumable request is available.
 * @param {string}   message         - Import message.
 * @param {string}   hint            - Actionable hint for error resolution.
 * @param {string}   sessionId       - Session of the finished run, for its log.
 */
const Success = ({
	importComplete,
	handleReset,
	handleResume,
	handleStartOver,
	canResume,
	message,
	hint,
	sessionId = '',
}) => {
	const [logEntries, setLogEntries] = useState([]);

	/**
	 * Fetch this run's log once, when the result screen appears.
	 */
	useEffect(() => {
		if (!sessionId) {
			return;
		}

		(async () => {
			try {
				const response = await Api.get(
					`/sd/edi/v1/import/log?session_id=${encodeURIComponent(
						sessionId
					)}`,
					{}
				);

				if (
					response.data &&
					response.data.success &&
					Array.isArray(response.data.data)
				) {
					setLogEntries(response.data.data);
				}
			} catch (error) {
				console.error(error);
			}
		})();
	}, [sessionId]);

	/**
	 * Inline collapsible log for this run, shown on both success and failure.
	 */
	const logSection = logEntries.length ? (
		<div className="edi-import-log-inline">
			<Collapse
				ghost
				items={[
					{
						key: 'log',
						label: `${
							sdEdiAdminParams.logDetailsLabel || 'Import details'
						} (${logEntries.length})`,
						children: (
							<ul className="edi-inline-log-list">
								{[...logEntries].reverse().map((entry) => (
									<li
										key={entry.id}
										className={`edi-log-line level-${entry.level}`}
									>
										<span
											className="edi-log-dot"
											style={{
												background:
													LEVEL_COLORS[entry.level] ||
													LEVEL_COLORS.info,
											}}
										/>
										<span className="edi-log-msg">
											{entry.message}
										</span>
									</li>
								))}
							</ul>
						),
					},
				]}
			/>
			{sdEdiAdminParams.logPageUrl && (
				<a
					className="edi-view-full-log"
					href={sdEdiAdminParams.logPageUrl}
					target="_self"
				>
					{sdEdiAdminParams.viewFullLog || 'View full log'} ↗
				</a>
			)}
		</div>
	) : null;

	if (importComplete) {
		/**
		 * Handle 'View Site' button behavior.
		 */
		const handleViewSite = () => {
			const homeUrl = sdEdiAdminParams.homeUrl;
			window.open(homeUrl, '_blank');
		};

		return (
			<>
				<div className="ant-result ant-result-success">
					<div className="ant-result-icon">
						<span
							role="img"
							aria-label="check-circle"
							className="custom-icon"
						>
							<div className="check-container">
								<div className="check-background">
									<svg
										viewBox="0 0 65 51"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<path
											d="M7 25L27.3077 44L58.5 7"
											stroke="white"
											strokeWidth="13"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								</div>
								<div className="check-shadow"></div>
							</div>
						</span>
					</div>
					<div className="ant-result-title">
						<h3>{message}</h3>
					</div>
					{logSection}
					<div className="ant-result-extra edi-d-flex edi-justify-content-center">
						<Button key="close" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnClose}</span>
						</Button>
						<Button
							key="view-site"
							type="primary"
							onClick={handleViewSite}
						>
							<span>{sdEdiAdminParams.btnViewSite}</span>
							<ExportOutlined />
						</Button>
					</div>
				</div>
			</>
		);
	}

	return (
		<>
			<Result
				status="error"
				title={message}
				subTitle={hint || null}
				extra={[
					<Button key="close" onClick={handleReset}>
						<CloseOutlined />
						<span>{sdEdiAdminParams.btnClose}</span>
					</Button>,
					canResume && (
						<Button
							key="resume"
							type="primary"
							onClick={handleResume}
						>
							<RollbackOutlined />
							<span>
								{sdEdiAdminParams.btnResume || 'Resume Import'}
							</span>
						</Button>
					),
					<Button key="start-over" onClick={handleStartOver}>
						<RedoOutlined />
						<span>
							{sdEdiAdminParams.btnStartOver || 'Start Over'}
						</span>
					</Button>,
				].filter(Boolean)}
			/>
			{logSection}
		</>
	);
};

export default Success;
