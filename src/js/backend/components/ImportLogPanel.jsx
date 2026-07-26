import React, { useState, useEffect } from 'react';
import { Collapse, Skeleton, Empty, Dropdown, Button, message } from 'antd';
import {
	PictureOutlined,
	DownloadOutlined,
	ClockCircleOutlined,
	ShareAltOutlined,
	CopyOutlined,
	FileTextOutlined,
	ExportOutlined,
} from '@ant-design/icons';
import useSharedDataStore from '../utils/sharedDataStore';
import { decodeEntities } from '../utils/decodeEntities';
import {
	REGEN_SLUG,
	formatDuration,
	formatEntryTime,
	formatRunTime,
	runName,
	runStatusText,
} from '../utils/logFormat';
import {
	buildReport,
	copyText,
	downloadText,
	runFileName,
} from '../utils/logShare';

/* global sdEdiAdminParams */

/**
 * Per-level accent colours for entry dots and the run status pill.
 */
const LEVEL_COLORS = {
	error: '#d63638',
	warning: '#dba617',
	success: '#00a32a',
	info: '#2d74d5',
	interrupted: '#e8830c',
};

/**
 * Header row for one import run.
 *
 * @param {Object} run - The run record.
 * @return {JSX.Element} Header node.
 */
const runLabel = (run) => {
	const isRegen = run.demo_slug === REGEN_SLUG;
	const duration = formatDuration(run);

	return (
		<div className="edi-log-run" data-panel-key={run.session_id}>
			<span
				className={`edi-log-run-icon is-${isRegen ? 'regen' : 'import'}`}
			>
				{isRegen ? <PictureOutlined /> : <DownloadOutlined />}
			</span>
			<span className="edi-log-run-name">{runName(run)}</span>
			<span className="edi-log-run-time">
				{formatRunTime(run.started_at)}
			</span>
			<span className={`edi-log-run-status is-${run.status}`}>
				{runStatusText(run)}
			</span>
			{duration && (
				<span
					className="edi-log-run-duration"
					title={
						sdEdiAdminParams.logDurationLabel || 'Import duration'
					}
				>
					<ClockCircleOutlined />
					{duration}
				</span>
			)}
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
	const [animating, setAnimating] = useState(false);
	const { logData, fetchLogData, fetchServerData } = useSharedDataStore();

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
		// While the panel height animates, antd sets overflow:hidden on the
		// content, which momentarily makes it the sticky scroll-root and shifts
		// the floating share button — snapping it back when the animation ends.
		// Suspend sticky for the duration so it stays put through the motion.
		setAnimating(true);
		setTimeout(() => setAnimating(false), 340);
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

	/**
	 * Resolves the System Status data for the support bundle, fetching it on
	 * demand when the sibling tab was never opened. The environment block is
	 * optional — a failed fetch just yields a runs-only report.
	 *
	 * @return {Promise<?Object>} The server info map, or null.
	 */
	const ensureServerInfo = async () => {
		const current = useSharedDataStore.getState().serverData;

		if (current && current.success && current.data) {
			return current.data;
		}

		try {
			await fetchServerData('/sd/edi/v1/server/status');
		} catch {
			// Environment is a best-effort addition; proceed without it.
		}

		const fresh = useSharedDataStore.getState().serverData;

		return fresh && fresh.success ? fresh.data : null;
	};

	/**
	 * Copies or downloads a support bundle for the given runs.
	 *
	 * @param {Array}  runsToShare - Runs to include in the bundle.
	 * @param {string} action      - 'copy' or 'download'.
	 * @param {string} filename    - File name for the download action.
	 */
	const shareRuns = async (runsToShare, action, filename) => {
		const serverInfo = await ensureServerInfo();
		const report = buildReport(runsToShare, serverInfo);

		if (action === 'download') {
			downloadText(filename, report);
			return;
		}

		const copied = await copyText(report);

		// Center the toast (marginTop: 40vh) to match the Copy System Data
		// confirmation on the sibling System Status tab.
		if (copied) {
			message.success({
				content:
					sdEdiAdminParams.logShareCopied ||
					'Import log copied to clipboard.',
				duration: 3,
				style: { marginTop: '40vh' },
			});
		} else {
			message.error({
				content:
					sdEdiAdminParams.logShareCopyFailed ||
					'Could not copy — use Download instead.',
				duration: 3,
				style: { marginTop: '40vh' },
			});
		}
	};

	/**
	 * Builds the antd dropdown menu config for a share control.
	 *
	 * @param {Array}  runsToShare - Runs the control shares.
	 * @param {string} filename    - Download file name.
	 * @return {Object} Menu prop for antd Dropdown.
	 */
	const shareMenu = (runsToShare, filename) => ({
		items: [
			{
				key: 'copy',
				icon: <CopyOutlined />,
				label: sdEdiAdminParams.logShareCopy || 'Copy to clipboard',
			},
			{
				key: 'download',
				icon: <FileTextOutlined />,
				label: sdEdiAdminParams.logShareDownload || 'Download .txt',
			},
		],
		onClick: ({ key, domEvent }) => {
			domEvent.stopPropagation();
			shareRuns(runsToShare, key, filename);
		},
	});

	const items = runs.map((run) => ({
		key: run.session_id,
		label: runLabel(run),
		children: (
			<div className="edi-log-run-body">
				<div className="edi-log-run-actions">
					<Dropdown
						menu={shareMenu([run], runFileName(run))}
						trigger={['click']}
						placement="bottomRight"
					>
						<Button
							type="primary"
							shape="circle"
							className="edi-log-share-fab"
							icon={<ShareAltOutlined />}
							aria-label={
								sdEdiAdminParams.logShareLabel || 'Share'
							}
							title={sdEdiAdminParams.logShareLabel || 'Share'}
						/>
					</Dropdown>
				</div>
				{runEntries(run.entries)}
			</div>
		),
	}));

	let content;

	if (loading && !runs.length) {
		content = (
			<>
				{/* Reserve the toolbar row so the layout doesn't jump when the
				    real "Export all" button appears after loading. */}
				<div className="edi-log-toolbar">
					<Skeleton.Button active size="small" shape="round" />
				</div>
				<div className="skeleton-wrapper">
					{Array.from({ length: 4 }).map((_, i) => (
						<div className="list-skeleton details" key={i}>
							<Skeleton paragraph={{ rows: 1 }} active />
						</div>
					))}
				</div>
			</>
		);
	} else if (logData && logData.success === false) {
		// Never render blank on a failed fetch. ErrorMessage expects an
		// object ({text, btnText, btnUrl}) used by the import flow; the log
		// store returns a plain string, so use Empty with a guaranteed
		// fallback description instead.
		content = (
			<Empty
				description={
					errorMessage ||
					sdEdiAdminParams.logFetchError ||
					'Could not load the import log. Please reload the page and try again.'
				}
			/>
		);
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
			<>
				<div className="edi-log-toolbar">
					<Dropdown
						menu={shareMenu(runs, 'edi-import-log-all.txt')}
						trigger={['click']}
						placement="bottomRight"
					>
						<Button
							type="primary"
							className="edi-log-export-btn"
							icon={<ExportOutlined />}
						>
							{sdEdiAdminParams.logExportAll || 'Export all'}
						</Button>
					</Dropdown>
				</div>
				<Collapse
					className="edi-log-collapse edi-fade-in"
					bordered={false}
					accordion
					expandIconPosition="end"
					items={items}
					activeKey={activePanel}
					onChange={handleAccordionChange}
				/>
			</>
		);
	}

	return (
		<div className={`edi-log-panel${animating ? ' is-animating' : ''}`}>
			{content}
		</div>
	);
};

export default ImportLogPanel;
