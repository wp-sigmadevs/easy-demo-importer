import React, { useState, useEffect } from 'react';
import { Modal, Input, Alert } from 'antd';

/* global sdEdiAdminParams */

/**
 * Controlled modal that enforces typing the exact subsite host before
 * confirming a destructive action (DB reset on a multisite subsite).
 *
 * Props:
 *  - visible: boolean — modal open state
 *  - title: string — modal title
 *  - description: string — explanatory text shown above the input
 *  - onConfirm: () => void — called when user clicks OK with matching input
 *  - onCancel: () => void — called when user clicks Cancel or closes the modal
 */
const DomainEchoConfirm = ({ visible, title, description, onConfirm, onCancel }) => {
	const params = (typeof sdEdiAdminParams !== 'undefined') ? sdEdiAdminParams : {};
	const [typed, setTyped] = useState('');

	const expected = (params.currentBlogUrl || '')
		.replace(/^https?:\/\//, '')
		.replace(/\/$/, '');

	const matches = typed.trim() === expected && expected !== '';

	useEffect(() => {
		if (!visible) {
			setTyped('');
		}
	}, [visible]);

	const okTpl = params.i18nDomainEchoConfirm || 'I understand — reset %s';
	const okText = okTpl.replace('%s', expected);
	const introText = params.i18nDomainEchoIntro || 'Type the host below to confirm:';

	return (
		<Modal
			open={visible}
			title={title || params.i18nDomainEchoTitleFallback}
			onCancel={onCancel}
			onOk={() => {
				if (matches) {
					onConfirm();
				}
			}}
			okText={okText}
			okButtonProps={{ disabled: !matches, danger: true }}
			cancelText={params.i18nDomainEchoCancel || 'Cancel'}
			centered
			className="confirmation-modal sd-edi-domain-echo"
		>
			<Alert
				type="error"
				showIcon
				message={description}
				style={{ marginBottom: 12 }}
			/>
			<p>
				{introText} <code>{expected}</code>
			</p>
			<Input
				autoFocus
				value={typed}
				onChange={(e) => setTyped(e.target.value)}
				placeholder={expected}
			/>
		</Modal>
	);
};

export default DomainEchoConfirm;
