import React, { useEffect, useState } from 'react';
import {
	CheckCircleTwoTone,
	ExclamationCircleTwoTone,
	CloseCircleTwoTone,
	DownOutlined,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Per-status icon for a readiness row and for the overall summary bar.
 */
const ICONS = {
	pass: <CheckCircleTwoTone twoToneColor="#00a32a" />,
	warn: <ExclamationCircleTwoTone twoToneColor="#dba617" />,
	fail: <CloseCircleTwoTone twoToneColor="#d63638" />,
};

/**
 * Pre-import readiness checklist, collapsed to a single summary bar.
 *
 * Almost every load is all-green, so an always-open list is pure noise and
 * (in the fixed-height modal) overflowed onto the backdrop. Instead the panel
 * shows one status line and only reveals the full list on click — or
 * automatically when something warns or blocks, so real problems are never
 * hidden. Uses a CSS max-height transition (no motion library) to match the
 * rest of the UI.
 *
 * @param {Object}  props         - Component props.
 * @param {Array}   props.checks  - Readiness checks from the /preflight endpoint.
 * @param {boolean} props.blocked - Whether a blocking check failed.
 * @return {JSX.Element|null} The panel, or null when there is nothing to show.
 */
const PreflightPanel = ({ checks = [], blocked = false }) => {
	const failCount = checks.filter((c) => c.status === 'fail').length;
	const warnCount = checks.filter((c) => c.status === 'warn').length;
	const total = checks.length;
	const passCount = total - failCount - warnCount;

	// A blocker outranks a warning outranks all-clear.
	let overall = 'pass';

	if (blocked || failCount > 0) {
		overall = 'fail';
	} else if (warnCount > 0) {
		overall = 'warn';
	}

	// Collapsed when all-green; open when there's something worth reading.
	const [open, setOpen] = useState(overall !== 'pass');

	// Checks arrive async — if they resolve to a warning/blocking state after
	// the first render, reveal the list rather than leaving it collapsed.
	useEffect(() => {
		if (overall !== 'pass') {
			setOpen(true);
		}
	}, [overall]);

	if (!checks.length) {
		return null;
	}

	const summaryText = () => {
		if (overall === 'fail') {
			const n = failCount || 1;

			return (
				sdEdiAdminParams.preflightSummaryFail ||
				`${n} issue${n > 1 ? 's' : ''} must be fixed to start import`
			);
		}

		if (overall === 'warn') {
			const ready =
				sdEdiAdminParams.preflightSummaryReady || 'Environment ready';

			return `${ready} · ${passCount} passed, ${warnCount} warning${
				warnCount > 1 ? 's' : ''
			}`;
		}

		const ready =
			sdEdiAdminParams.preflightSummaryReady || 'Environment ready';

		return `${ready} · ${total} checks passed`;
	};

	return (
		<div className={`edi-preflight is-${overall}${open ? ' is-open' : ''}`}>
			<button
				type="button"
				className="edi-preflight-summary"
				aria-expanded={open}
				onClick={() => setOpen((prev) => !prev)}
			>
				<span className="edi-preflight-summary-icon">
					{ICONS[overall]}
				</span>
				<span className="edi-preflight-summary-text">
					{summaryText()}
				</span>
				<DownOutlined className="edi-preflight-caret" />
			</button>

			<div className="edi-preflight-body" aria-hidden={!open}>
				<ul className="edi-preflight-list">
					{checks.map((check) => (
						<li
							key={check.id}
							className={`edi-preflight-item is-${check.status}`}
						>
							<span className="edi-preflight-item-icon">
								{ICONS[check.status] || ICONS.pass}
							</span>
							<span className="edi-preflight-item-label">
								{check.label}
							</span>
							<span className="edi-preflight-item-msg">
								{check.message}
							</span>
						</li>
					))}
				</ul>
			</div>
		</div>
	);
};

export default PreflightPanel;
