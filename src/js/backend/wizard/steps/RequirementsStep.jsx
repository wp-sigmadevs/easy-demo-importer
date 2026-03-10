import { Button, Alert, Spin, Tag } from 'antd';
import {
	CheckCircleOutlined,
	WarningOutlined,
	CloseCircleOutlined,
} from '@ant-design/icons';
import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Api } from '../../utils/Api';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const STATUS_ICON = {
	pass: <CheckCircleOutlined style={{ color: '#10b981' }} />,
	warn: <WarningOutlined style={{ color: '#f59e0b' }} />,
	fail: <CloseCircleOutlined style={{ color: '#ef4444' }} />,
};

const RequirementsStep = () => {
	const navigate = useNavigate();
	const [checks, setChecks] = useState([]);
	const [loading, setLoading] = useState(true);
	const [hasBlock, setHasBlock] = useState(false);
	const [footer, setFooter] = useState(null);
	const [back, setBack] = useState(null);

	useEffect(() => {
		Api.get('/sd/edi/v1/server/status')
			.then((res) => {
				// The API returns { success: true, data: { system_info: { fields: { ... } }, ... } }
				const sections = res.data?.data ?? {};
				const sysInfo = sections.system_info?.fields ?? {};
				
				const items = Object.entries(sysInfo).map(([key, field]) => ({
					key,
					label: field.label,
					value: field.value,
					status: field.error ? 'fail' : 'pass', // Simple mapping: if there's an error string, it's a fail
					hint: field.error || '',
				}));

				const blocked = items.some((i) => i.status === 'fail');
				setChecks(items);
				setHasBlock(blocked);
			})
			.catch((err) => {
				console.error('Failed to fetch server status:', err);
				setChecks([]);
			})
			.finally(() => setLoading(false));
	}, []);

	useEffect(() => {
		const nextEl = document.getElementById('edi-wizard-next-slot');
		const backEl = document.getElementById('edi-wizard-back-slot');
		if (nextEl) setFooter(nextEl);
		if (backEl) setBack(backEl);
	}, []);

	return (
		<>
			<h2 style={{ fontSize: 18, fontWeight: 700, marginBottom: 16 }}>
				Server Requirements
			</h2>

			{loading && (
				<Spin
					size="large"
					style={{ display: 'block', margin: '40px auto' }}
				/>
			)}

			{!loading && (
				<>
					{hasBlock && (
						<Alert
							type="error"
							message="One or more requirements are not met. Fix the issues below before continuing."
							style={{ marginBottom: 20 }}
							showIcon
						/>
					)}

					{checks.length === 0 && !hasBlock && (
						<Alert
							type="warning"
							message="Could not load server requirements. You can still proceed, but the import might fail if the server is not properly configured."
							style={{ marginBottom: 20 }}
						/>
					)}

					<div
						style={{ display: 'flex', flexDirection: 'column', gap: 8 }}
					>
						{checks.map((c, i) => (
							<div
								key={i}
								style={{
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									padding: '10px 14px',
									background: '#fafafa',
									borderRadius: 8,
									border: '1px solid #f0f0f0',
								}}
							>
								<span style={{ color: '#262626', fontSize: 14 }}>
									{STATUS_ICON[c.status]} {c.label}
									{c.hint && (
										<span
											style={{
												color: '#8c8c8c',
												fontSize: 12,
												marginLeft: 8,
											}}
										>
											— {c.hint}
										</span>
									)}
								</span>
								<Tag
									color={
										c.status === 'pass'
											? 'success'
											: c.status === 'warn'
											? 'warning'
											: 'error'
									}
								>
									{c.value}
								</Tag>
							</div>
						))}
					</div>
				</>
			)}

			{back &&
				ReactDOM.createPortal(
					<Button onClick={() => navigate('/wizard/welcome')}>
						Back
					</Button>,
					back
				)}

			{footer &&
				ReactDOM.createPortal(
					<Button
						type="primary"
						disabled={loading || hasBlock}
						onClick={() => navigate('/wizard/plugins')}
					>
						Next
					</Button>,
					footer
				)}
		</>
	);
};

export default RequirementsStep;
