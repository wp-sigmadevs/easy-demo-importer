import React, { useState, useEffect } from 'react';
import { Result, Button, Modal } from 'antd';
import {
	ExportOutlined,
	CloseOutlined,
	RedoOutlined,
	RollbackOutlined,
	FileTextOutlined,
	SyncOutlined,
	DeleteOutlined,
} from '@ant-design/icons';
import { Api } from '../../../utils/Api';

/* global sdEdiAdminParams */

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
 * @param {string}   demo            - The imported demo slug (for the retry request).
 * @param {string}   sessionId       - The finished run's session id (for retry).
 */
const Success = ({
	importComplete,
	handleReset,
	handleResume,
	handleStartOver,
	canResume,
	message,
	hint,
	demo = '',
	sessionId = '',
	manual = 'false',
	manualKey = '',
}) => {
	const [failedCount, setFailedCount] = useState(0);
	const [rollbackAvailable, setRollbackAvailable] = useState(false);
	const [rolling, setRolling] = useState(false);
	const [discard, setDiscard] = useState({ running: false, done: false });
	const [retry, setRetry] = useState({
		running: false,
		done: false,
		recovered: 0,
		stillFailed: 0,
	});

	/**
	 * On a completed run, ask how many media downloads failed (retry-able).
	 */
	useEffect(() => {
		if (!importComplete || !sessionId) {
			return;
		}

		Api.get('/sd/edi/v1/failed-media', {
			params: { session_id: sessionId },
		})
			.then((res) => {
				if (res?.data?.success) {
					setFailedCount(res.data.data.count || 0);
					setRollbackAvailable(!!res.data.data.rollbackAvailable);
				}
			})
			.catch(() => {});
	}, [importComplete, sessionId]);

	/**
	 * Rolls the site back to the pre-import restore point after a hard confirm.
	 * On success the admin is reloaded so the reverted state is shown.
	 */
	const handleRollback = () => {
		Modal.confirm({
			title: sdEdiAdminParams.rollbackTitle || 'Roll back this import?',
			content:
				sdEdiAdminParams.rollbackWarning ||
				'This restores your site to the moment before this import. Anything created since (new posts, orders, users) will be permanently lost.',
			okText: sdEdiAdminParams.rollbackConfirm || 'Roll back',
			okButtonProps: { danger: true },
			cancelText: sdEdiAdminParams.confirmNo || 'Cancel',
			centered: true,
			onOk: () => {
				setRolling(true);

				return Api.post('/sd/edi/v1/rollback', {})
					.then((res) => {
						if (res?.data?.success) {
							window.location.reload();
						} else {
							setRolling(false);
						}
					})
					.catch(() => setRolling(false));
			},
		});
	};

	/**
	 * Discards the restore point to reclaim the disk it holds. Non-destructive to
	 * the site, but removes the ability to roll this import back — so it confirms
	 * first, then hides the rollback affordance.
	 */
	const handleDiscard = () => {
		Modal.confirm({
			title:
				sdEdiAdminParams.discardConfirmTitle ||
				'Discard the restore point?',
			content:
				sdEdiAdminParams.discardConfirmText ||
				'This frees the disk space used by the backup. You will no longer be able to roll this import back.',
			okText: sdEdiAdminParams.discardConfirm || 'Discard',
			cancelText: sdEdiAdminParams.confirmNo || 'Cancel',
			centered: true,
			onOk: () => {
				setDiscard({ running: true, done: false });

				return Api.post('/sd/edi/v1/discard-restore-point', {})
					.then((res) => {
						if (res?.data?.success) {
							setRollbackAvailable(false);
							setDiscard({ running: false, done: true });
						} else {
							setDiscard({ running: false, done: false });
						}
					})
					.catch(() => setDiscard({ running: false, done: false }));
			},
		});
	};

	/**
	 * Drives the resumable retry loop against the admin-ajax endpoint until the
	 * whole failed list has been re-attempted.
	 *
	 * @param {number} cursor      - Position in the failed list.
	 * @param {number} recovered   - Running recovered tally.
	 * @param {number} stillFailed - Running still-failed tally.
	 */
	const retryStep = (cursor, recovered, stillFailed) => {
		const body = new FormData();
		body.append('action', 'sd_edi_retry_media');
		body.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
		body.append('demo', demo);
		body.append('manual', manual);
		body.append('manualKey', manualKey);
		body.append('retrySession', sessionId);
		body.append('retryCursor', cursor);
		body.append('recovered', recovered);
		body.append('stillFailed', stillFailed);

		fetch(sdEdiAdminParams.ajaxUrl, {
			method: 'POST',
			body,
			credentials: 'same-origin',
		})
			.then((r) => r.json())
			.then((res) => {
				if (!res?.success) {
					setRetry((prev) => ({ ...prev, running: false }));
					return;
				}

				const d = res.data;
				setRetry({
					running: !d.done,
					done: d.done,
					recovered: d.recovered,
					stillFailed: d.stillFailed,
				});

				if (!d.done) {
					retryStep(d.cursor, d.recovered, d.stillFailed);
				}
			})
			.catch(() => setRetry((prev) => ({ ...prev, running: false })));
	};

	const handleRetry = () => {
		setRetry({ running: true, done: false, recovered: 0, stillFailed: 0 });
		retryStep(0, 0, 0);
	};

	/**
	 * Retry-failed-media notice, shown on a completed run that had download
	 * failures (e.g. images blocked by a proxy). Re-attempts just those.
	 */
	const retryMedia =
		importComplete && (failedCount > 0 || retry.done) ? (
			<div
				className="edi-retry-media"
				style={{ margin: '4px auto 16px', maxWidth: 520 }}
			>
				{retry.done ? (
					<p style={{ margin: 0, color: '#50575e' }}>
						{`${sdEdiAdminParams.retryFinished || 'Media retry finished'} — ${retry.recovered} ${sdEdiAdminParams.retryRecovered || 'recovered'}, ${retry.stillFailed} ${sdEdiAdminParams.retryStillFailed || 'still failed'}.`}
					</p>
				) : (
					<>
						<p style={{ margin: '0 0 8px', color: '#50575e' }}>
							{`${failedCount} ${sdEdiAdminParams.retryFailedNotice || 'media file(s) could not be downloaded.'}`}
						</p>
						<Button loading={retry.running} onClick={handleRetry}>
							<SyncOutlined />
							<span>
								{sdEdiAdminParams.retryButton ||
									'Retry failed images'}
							</span>
						</Button>
					</>
				)}
			</div>
		) : null;

	/**
	 * Keep-vs-discard prompt for the restore point. Shown on a happy import when a
	 * restore point exists: keeping it (do nothing) preserves rollback; discarding
	 * reclaims the disk the backup holds. Roll Back itself lives in the action row.
	 */
	const restorePointNotice =
		importComplete && (rollbackAvailable || discard.done) ? (
			<div
				className="edi-restore-point-notice"
				style={{ margin: '4px auto 16px', maxWidth: 520 }}
			>
				{discard.done ? (
					<p style={{ margin: 0, color: '#50575e' }}>
						{sdEdiAdminParams.discardDone ||
							'Restore point discarded — disk space reclaimed.'}
					</p>
				) : (
					<>
						<p style={{ margin: '0 0 8px', color: '#50575e' }}>
							{sdEdiAdminParams.restorePointKeepNotice ||
								'A restore point is holding a backup of your previous site, which uses disk space. Keep it to stay able to roll back, or discard it now to free the space.'}
						</p>
						<Button
							loading={discard.running}
							onClick={handleDiscard}
						>
							<DeleteOutlined />
							<span>
								{sdEdiAdminParams.discardButton ||
									'Discard restore point'}
							</span>
						</Button>
					</>
				)}
			</div>
		) : null;

	/**
	 * Link to the dedicated Import Log page (always available — a static admin
	 * URL, not scoped to this run). Rendered as a button alongside Close /
	 * View Site rather than duplicating entries inline: the dedicated page can
	 * show more than a cramped inline list ever could, and it already defaults
	 * to the most recent run.
	 */
	const viewLogButton = sdEdiAdminParams.logPageUrl ? (
		<Button
			key="view-log"
			href={sdEdiAdminParams.logPageUrl}
			target="_self"
		>
			<FileTextOutlined />
			<span>{sdEdiAdminParams.viewFullLog || 'View Log'}</span>
		</Button>
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
					{retryMedia}
					{restorePointNotice}
					<div className="ant-result-extra edi-d-flex edi-justify-content-center">
						<Button key="close" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnClose}</span>
						</Button>
						{viewLogButton}
						{rollbackAvailable && (
							<Button
								key="rollback"
								danger
								loading={rolling}
								onClick={handleRollback}
							>
								<RollbackOutlined />
								<span>
									{sdEdiAdminParams.rollbackButton ||
										'Roll Back'}
								</span>
							</Button>
						)}
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
		<Result
			status="error"
			title={message}
			subTitle={hint || null}
			extra={[
				<Button key="close" onClick={handleReset}>
					<CloseOutlined />
					<span>{sdEdiAdminParams.btnClose}</span>
				</Button>,
				viewLogButton,
				canResume && (
					<Button key="resume" type="primary" onClick={handleResume}>
						<RollbackOutlined />
						<span>
							{sdEdiAdminParams.btnResume || 'Resume Import'}
						</span>
					</Button>
				),
				<Button key="start-over" onClick={handleStartOver}>
					<RedoOutlined />
					<span>{sdEdiAdminParams.btnStartOver || 'Start Over'}</span>
				</Button>,
			].filter(Boolean)}
		/>
	);
};

export default Success;
