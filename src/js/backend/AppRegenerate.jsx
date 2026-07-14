import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Button, Progress, Checkbox, Tooltip } from 'antd';
import {
	PictureOutlined,
	ReloadOutlined,
	CheckCircleFilled,
	InfoCircleOutlined,
} from '@ant-design/icons';
import Header from './Layouts/Header';
import Support from './components/Support';

/* global sdEdiAdminParams */

const emptyCounts = { after: 0, regenerated: 0, skipped: 0, failed: 0 };

/**
 * Regenerate Thumbnails page — a React route on the Easy Demo Importer screen.
 *
 * Drives the resumable, time-boxed `sd_edi_regen_thumbnails` AJAX handler in
 * small batches, so a large media library is processed without timing out.
 * Mirrors the Status page's shell (Header + wrapper + floating back button)
 * so it matches the rest of the plugin's admin UI.
 */
const AppRegenerate = () => {
	// idle | running | done | error
	const [phase, setPhase] = useState('idle');
	const [total, setTotal] = useState(null);
	const [counts, setCounts] = useState(emptyCounts);
	const [force, setForce] = useState(false);

	// Kept in a ref so a stale render never re-issues an already-superseded run.
	const runningRef = useRef(false);

	const post = useCallback((fields) => {
		const body = new URLSearchParams({
			action: sdEdiAdminParams.regenAction,
			nonce: sdEdiAdminParams.regenNonce,
			...fields,
		});

		return fetch(sdEdiAdminParams.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		}).then((r) => r.json());
	}, []);

	// Probe once on mount for the library size (no work done server-side).
	useEffect(() => {
		let alive = true;

		post({ probe: 'true' })
			.then((res) => {
				if (alive && res && res.success) {
					setTotal(res.data.total);
				}
			})
			.catch(() => alive && setTotal(0));

		return () => {
			alive = false;
			runningRef.current = false;
		};
	}, [post]);

	const step = useCallback(
		(state) => {
			if (!runningRef.current) {
				return;
			}

			post({
				after: state.after,
				force: force ? 'true' : 'false',
				regenerated: state.regenerated,
				skipped: state.skipped,
				failed: state.failed,
			})
				.then((res) => {
					if (!runningRef.current) {
						return;
					}

					if (!res || !res.success) {
						setPhase('error');
						runningRef.current = false;
						return;
					}

					const d = res.data;
					const next = {
						after: d.after,
						regenerated: d.regenerated,
						skipped: d.skipped,
						failed: d.failed,
					};

					setCounts(next);
					setTotal(d.total);

					if (d.done) {
						setPhase('done');
						runningRef.current = false;
					} else {
						step(next);
					}
				})
				.catch(() => {
					if (runningRef.current) {
						setPhase('error');
						runningRef.current = false;
					}
				});
		},
		[post, force]
	);

	const start = () => {
		setCounts(emptyCounts);
		setPhase('running');
		runningRef.current = true;
		step(emptyCounts);
	};

	const handleBackBtn = () => {
		window.open(sdEdiAdminParams.importPageUrl, '_self');
	};

	const processed = counts.regenerated + counts.skipped + counts.failed;
	const percent =
		total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
	const running = phase === 'running';
	const done = phase === 'done';
	const isEmpty = total === 0;

	const startLabel = running
		? sdEdiAdminParams.regenRunningBtn
		: done
			? sdEdiAdminParams.regenDoneBtn
			: sdEdiAdminParams.regenStartBtn;

	const stats = [
		{
			key: 'processed',
			label: sdEdiAdminParams.regenStatProcessed,
			value: processed,
		},
		{
			key: 'regenerated',
			label: sdEdiAdminParams.regenStatRegenerated,
			value: counts.regenerated,
		},
		{
			key: 'skipped',
			label: sdEdiAdminParams.regenStatSkipped,
			value: counts.skipped,
		},
		{
			key: 'failed',
			label: sdEdiAdminParams.regenStatFailed,
			value: counts.failed,
		},
	];

	return (
		<>
			<div className="wrap edi-server-status-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading={
						sdEdiAdminParams.regenHeading || 'Regenerate Thumbnails'
					}
				/>

				<div className="edi-content">
					<div className="edi-container edi-regenerate">
						<div className="edi-regenerate-card edi-fade-in">
							<div className="edi-regenerate-icon">
								<PictureOutlined />
							</div>

							<p className="edi-regenerate-intro">
								{sdEdiAdminParams.regenIntro}
							</p>

							<div className="edi-regenerate-count">
								{null === total ? (
									<span className="edi-regenerate-count-num is-loading">
										&nbsp;
									</span>
								) : (
									<span className="edi-regenerate-count-num">
										{new Intl.NumberFormat().format(total)}
									</span>
								)}
								<span className="edi-regenerate-count-label">
									{sdEdiAdminParams.regenImagesFound}
								</span>
							</div>

							{!isEmpty && (
								<label className="edi-regenerate-force">
									<Checkbox
										checked={force}
										disabled={running}
										onChange={(e) =>
											setForce(e.target.checked)
										}
									/>
									<span className="edi-regenerate-force-text">
										{sdEdiAdminParams.regenForceLabel}
										<Tooltip
											title={
												sdEdiAdminParams.regenForceHint
											}
										>
											<InfoCircleOutlined className="edi-regenerate-force-help" />
										</Tooltip>
									</span>
								</label>
							)}

							{(running || done) && (
								<div className="edi-regenerate-progress edi-fade-in">
									<Progress
										percent={percent}
										status={done ? 'success' : 'active'}
										strokeColor={{
											from: 'rgb(45, 116, 213)',
											to: 'rgb(121, 137, 212)',
										}}
									/>

									<div className="edi-regenerate-stats">
										{stats.map((s) => (
											<div
												className={`edi-regenerate-stat is-${s.key}`}
												key={s.key}
											>
												<span className="edi-regenerate-stat-value">
													{new Intl.NumberFormat().format(
														s.value
													)}
												</span>
												<span className="edi-regenerate-stat-label">
													{s.label}
												</span>
											</div>
										))}
									</div>

									<p className="edi-regenerate-message">
										{done ? (
											<>
												<CheckCircleFilled className="is-done" />
												{sdEdiAdminParams.regenComplete}
											</>
										) : (
											sdEdiAdminParams.regenRunning
										)}
									</p>
								</div>
							)}

							{phase === 'error' && (
								<p className="edi-regenerate-error edi-fade-in">
									{sdEdiAdminParams.regenError}
								</p>
							)}

							<div className="edi-regenerate-actions">
								<Button
									type="primary"
									size="large"
									icon={<ReloadOutlined spin={running} />}
									loading={null === total}
									disabled={running || isEmpty}
									onClick={start}
								>
									{isEmpty
										? sdEdiAdminParams.regenEmpty
										: startLabel}
								</Button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div className="edi-server-status">
				<Button
					className="edi-server-status-btn"
					type="primary"
					onClick={handleBackBtn}
				>
					<span>{sdEdiAdminParams.importPageBtnText}</span>
				</Button>
			</div>

			{'yes' === sdEdiAdminParams.enableSupportButton && <Support />}
		</>
	);
};

export default AppRegenerate;
