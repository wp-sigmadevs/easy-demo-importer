import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Button, Progress, Switch, Tooltip } from 'antd';
import {
	PictureOutlined,
	ReloadOutlined,
	CheckCircleFilled,
	InfoCircleOutlined,
	CheckOutlined,
	MinusOutlined,
	CloseOutlined,
} from '@ant-design/icons';
import Header from './Layouts/Header';
import Support from './components/Support';

/* global sdEdiAdminParams */

const emptyCounts = { regenerated: 0, skipped: 0, failed: 0 };

// Newest images to keep in the DOM. The counts stay exact; only the rendered
// list is windowed so a huge library doesn't grow an unbounded node tree.
const MAX_ROWS = 120;

const statusIcon = {
	regenerated: <CheckOutlined />,
	skipped: <MinusOutlined />,
	failed: <CloseOutlined />,
};

/**
 * Regenerate Thumbnails page — a React route on the Easy Demo Importer screen.
 *
 * Drives the resumable, time-boxed `sd_edi_regen_thumbnails` AJAX handler and
 * streams each processed image into a live log. Network responses arrive in
 * batches, but a reveal queue drains them one image at a time so the percentage
 * and list climb smoothly regardless of batch size (in one-at-a-time mode the
 * server already returns a single image per request).
 */
const AppRegenerate = () => {
	// idle | running | done | error
	const [phase, setPhase] = useState('idle');
	const [total, setTotal] = useState(null);
	const [counts, setCounts] = useState(emptyCounts);
	const [items, setItems] = useState([]);
	const [overflowed, setOverflowed] = useState(false);
	const [force, setForce] = useState(false);
	const [single, setSingle] = useState(false);

	const runningRef = useRef(false);
	const queueRef = useRef([]);
	const serverDoneRef = useRef(false);
	const drainRef = useRef(null);
	const listRef = useRef(null);
	const sessionRef = useRef('');

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

	const stopDrain = useCallback(() => {
		if (drainRef.current) {
			clearInterval(drainRef.current);
			drainRef.current = null;
		}
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
			stopDrain();
		};
	}, [post, stopDrain]);

	// Auto-scroll the log to the newest row.
	useEffect(() => {
		if (listRef.current) {
			listRef.current.scrollTop = listRef.current.scrollHeight;
		}
	}, [items]);

	const step = useCallback(
		(state) => {
			if (!runningRef.current) {
				return;
			}

			post({
				after: state.after,
				force: force ? 'true' : 'false',
				single: single ? 'true' : 'false',
				session: sessionRef.current,
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
						stopDrain();
						return;
					}

					const d = res.data;

					// The server mints the run id on the first request; keep it so
					// every later request groups under the same activity-log run.
					if (d.session) {
						sessionRef.current = d.session;
					}

					if (Array.isArray(d.items) && d.items.length) {
						queueRef.current.push(...d.items);
					}

					if (d.done) {
						// Let the drainer flush the remaining queue, then finish.
						serverDoneRef.current = true;
						runningRef.current = false;
					} else {
						step({
							after: d.after,
							regenerated: d.regenerated,
							skipped: d.skipped,
							failed: d.failed,
						});
					}
				})
				.catch(() => {
					if (runningRef.current) {
						setPhase('error');
						runningRef.current = false;
						stopDrain();
					}
				});
		},
		[post, force, single, stopDrain]
	);

	const start = () => {
		queueRef.current = [];
		serverDoneRef.current = false;
		sessionRef.current = '';
		setCounts(emptyCounts);
		setItems([]);
		setOverflowed(false);
		setPhase('running');
		runningRef.current = true;

		stopDrain();
		drainRef.current = setInterval(() => {
			const queue = queueRef.current;

			if (!queue.length) {
				// Server finished and nothing left to reveal → we're done.
				if (serverDoneRef.current) {
					stopDrain();
					setPhase('done');
				}
				return;
			}

			// Reveal faster when the queue is backed up, so the log keeps pace
			// with fast batches without ever feeling like a single jump.
			const take = Math.max(1, Math.ceil(queue.length / 15));
			const chunk = queue.splice(0, take);

			setCounts((prev) => {
				const next = { ...prev };
				chunk.forEach((it) => {
					next[it.status] = (next[it.status] || 0) + 1;
				});
				return next;
			});

			setItems((prev) => {
				const merged = prev.concat(chunk);
				if (merged.length > MAX_ROWS) {
					setOverflowed(true);
					return merged.slice(-MAX_ROWS);
				}
				return merged;
			});
		}, 40);

		step({ after: 0, regenerated: 0, skipped: 0, failed: 0 });
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

	const statLabels = {
		processed: sdEdiAdminParams.regenStatProcessed,
		regenerated: sdEdiAdminParams.regenStatRegenerated,
		skipped: sdEdiAdminParams.regenStatSkipped,
		failed: sdEdiAdminParams.regenStatFailed,
	};

	const stats = [
		{ key: 'processed', value: processed },
		{ key: 'regenerated', value: counts.regenerated },
		{ key: 'skipped', value: counts.skipped },
		{ key: 'failed', value: counts.failed },
	];

	const fmt = (n) => new Intl.NumberFormat().format(n);

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
					<div
						className={`edi-container edi-regenerate${
							items.length ? ' has-list' : ''
						}`}
					>
						<div
							className={`edi-regenerate-card edi-fade-in${
								items.length ? ' has-list' : ''
							}`}
						>
							<div className="edi-regenerate-main">
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
											{fmt(total)}
										</span>
									)}
									<span className="edi-regenerate-count-label">
										{sdEdiAdminParams.regenImagesFound}
									</span>
								</div>

								{!isEmpty && (
									<div className="edi-regenerate-options">
										<label className="edi-regenerate-option">
											<span className="edi-regenerate-option-text">
												{
													sdEdiAdminParams.regenForceLabel
												}
												<Tooltip
													title={
														sdEdiAdminParams.regenForceHint
													}
												>
													<InfoCircleOutlined className="edi-regenerate-option-help" />
												</Tooltip>
											</span>
											<Switch
												size="small"
												checked={force}
												disabled={running}
												onChange={setForce}
											/>
										</label>

										<label className="edi-regenerate-option">
											<span className="edi-regenerate-option-text">
												{
													sdEdiAdminParams.regenSingleLabel
												}
												<Tooltip
													title={
														sdEdiAdminParams.regenSingleHint
													}
												>
													<InfoCircleOutlined className="edi-regenerate-option-help" />
												</Tooltip>
											</span>
											<Switch
												size="small"
												checked={single}
												disabled={running}
												onChange={setSingle}
											/>
										</label>
									</div>
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
														{fmt(s.value)}
													</span>
													<span className="edi-regenerate-stat-label">
														{statLabels[s.key]}
													</span>
												</div>
											))}
										</div>

										<p className="edi-regenerate-message">
											{done ? (
												<>
													<CheckCircleFilled className="is-done" />
													{
														sdEdiAdminParams.regenComplete
													}
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

							{items.length > 0 && (
								<div className="edi-regenerate-side edi-fade-in">
									<div className="edi-regenerate-side-inner">
										<div className="edi-regenerate-list-head">
											<span>
												{
													sdEdiAdminParams.regenListTitle
												}
											</span>
											{overflowed && (
												<span className="edi-regenerate-list-note">
													{
														sdEdiAdminParams.regenListOverflow
													}
												</span>
											)}
										</div>
										<ul
											className="edi-regenerate-list"
											ref={listRef}
										>
											{items.map((it) => (
												<li
													className={`edi-regenerate-row is-${it.status}`}
													key={it.id}
												>
													<span className="edi-regenerate-row-thumb">
														{it.thumb ? (
															<img
																src={it.thumb}
																alt=""
																loading="lazy"
															/>
														) : (
															<PictureOutlined />
														)}
													</span>
													<span className="edi-regenerate-row-title">
														{it.title}
													</span>
													<span className="edi-regenerate-row-status">
														{statusIcon[it.status]}
														{statLabels[it.status]}
													</span>
												</li>
											))}
										</ul>
									</div>
								</div>
							)}
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
