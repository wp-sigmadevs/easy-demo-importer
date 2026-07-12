import React from 'react';
import {
	CheckCircleTwoTone,
	ExclamationCircleTwoTone,
	CloseCircleTwoTone,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Per-status icon for a readiness check row.
 */
const ICONS = {
	pass: <CheckCircleTwoTone twoToneColor="#00a32a" />,
	warn: <ExclamationCircleTwoTone twoToneColor="#dba617" />,
	fail: <CloseCircleTwoTone twoToneColor="#d63638" />,
};

/**
 * Compact pre-import readiness checklist.
 *
 * @param {Object}  props         - Component props.
 * @param {Array}   props.checks  - Readiness checks from the /preflight endpoint.
 * @param {boolean} props.blocked - Whether a blocking check failed.
 * @return {JSX.Element|null} The panel, or null when there is nothing to show.
 */
const PreflightPanel = ({ checks = [], blocked = false }) => {
	if (!checks.length) {
		return null;
	}

	return (
		<div className={`edi-preflight${blocked ? ' is-blocked' : ''}`}>
			<h3 style={{ marginBottom: 8 }}>
				{sdEdiAdminParams.preflightTitle || 'Readiness check'}
			</h3>

			{blocked && (
				<p
					className="edi-preflight-blocked warn-text"
					style={{ margin: '0 0 8px' }}
				>
					{sdEdiAdminParams.preflightBlocked ||
						'Resolve the failed checks below before importing.'}
				</p>
			)}

			<ul
				className="edi-preflight-list"
				style={{ listStyle: 'none', margin: 0, padding: 0 }}
			>
				{checks.map((check) => (
					<li
						key={check.id}
						className={`edi-preflight-item is-${check.status}`}
						style={{
							display: 'flex',
							alignItems: 'baseline',
							gap: 8,
							padding: '4px 0',
							fontSize: 13,
						}}
					>
						<span style={{ flex: '0 0 auto' }}>
							{ICONS[check.status] || ICONS.pass}
						</span>
						<span
							style={{
								flex: '0 0 auto',
								fontWeight: 600,
								minWidth: 130,
							}}
						>
							{check.label}
						</span>
						<span style={{ color: '#50575e' }}>
							{check.message}
						</span>
					</li>
				))}
			</ul>
		</div>
	);
};

export default PreflightPanel;
