import React from 'react';
import { List } from 'antd';
import {
	CheckCircleTwoTone,
	ExclamationCircleTwoTone,
	CloseCircleTwoTone,
} from '@ant-design/icons';

/**
 * Per-status icon for a readiness row. Same colors as the Required Plugins
 * list beside it (see getStatusIcon in utils/helpers.js) so the two lists
 * read as one visual language.
 */
const ICONS = {
	pass: <CheckCircleTwoTone twoToneColor="#52c41a" />,
	warn: <ExclamationCircleTwoTone twoToneColor="#faad14" />,
	fail: <CloseCircleTwoTone twoToneColor="#eb2f96" />,
};

/**
 * Pre-import readiness checklist, styled as a plain list to match the
 * Required Plugins list beside it rather than a separate boxed panel. The
 * full list is always shown — a blocking or warning state is rare enough,
 * and the list short enough, that collapsing it added a toggle nobody needed.
 *
 * @param {Object} props        - Component props.
 * @param {Array}  props.checks - Readiness checks from the /preflight endpoint.
 * @return {JSX.Element|null} The panel, or null when there is nothing to show.
 */
const PreflightPanel = ({ checks = [] }) => {
	if (!checks.length) {
		return null;
	}

	return (
		<div className="edi-preflight">
			<List
				className="edi-fade-in"
				dataSource={checks}
				renderItem={(check) => (
					<List.Item className={`is-${check.status}`}>
						<span className="edi-preflight-item-icon">
							{ICONS[check.status] || ICONS.pass}
						</span>
						<span className="edi-preflight-item-label">
							{check.label}
						</span>
						<span className="edi-preflight-item-msg">
							{check.message}
						</span>
					</List.Item>
				)}
			/>
		</div>
	);
};

export default PreflightPanel;
