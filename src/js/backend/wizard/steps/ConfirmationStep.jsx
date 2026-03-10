import { Button, Statistic, Row, Col, Spin, Alert } from 'antd';
import {
	FileTextOutlined,
	PictureOutlined,
	AppstoreOutlined,
	ThunderboltOutlined,
	InfoCircleOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import React, { useState, useEffect } from 'react';
import { useWizard } from '../WizardContext';
import { Api } from '../../utils/Api';
import ReactDOM from 'react-dom';

const ConfirmationStep = () => {
	const navigate = useNavigate();
	const { selectedDemo, dryRunStats, setDryRunStats, importOptions } =
		useWizard();
	const [footer, setFooter] = useState(null);
	const [back, setBack] = useState(null);

	useEffect(() => {
		if (!selectedDemo || dryRunStats) {
			return;
		}

		Api.get(`/sd/edi/v1/demo-stats?demo=${selectedDemo.slug ?? ''}`)
			.then((res) => setDryRunStats(res.data))
			.catch(() => {
				setDryRunStats({
					error: true,
					message:
						'Could not communicate with the server to analyse demo content.',
				});
			});
	}, [selectedDemo, dryRunStats, setDryRunStats]);

	useEffect(() => {
		const nextEl = document.getElementById('edi-wizard-next-slot');
		const backEl = document.getElementById('edi-wizard-back-slot');
		if (nextEl) {
			setFooter(nextEl);
		}
		if (backEl) {
			setBack(backEl);
		}
	}, []);

	const byType = dryRunStats?.by_type ?? {};
	const pages = byType.page ?? 0;
	const posts = byType.post ?? 0;
	const products = byType.product ?? 0;
	const attachments = dryRunStats?.attachments ?? 0;
	const total = dryRunStats?.total ?? 0;

	// Check if stats are actually available or if the server said "unavailable"
	const isUnavailable = dryRunStats?.unavailable || dryRunStats?.error;

	return (
		<>
			<h2 style={{ fontSize: 18, fontWeight: 700, marginBottom: 6 }}>
				Ready to Import
			</h2>
			<p style={{ color: '#8c8c8c', marginBottom: 24, fontSize: 14 }}>
				Review what will be imported, then click{' '}
				<strong>Start Import</strong>.
			</p>

			{!dryRunStats && (
				<div style={{ textAlign: 'center', padding: '40px 0' }}>
					<Spin size="large" />
					<p style={{ marginTop: 16, color: '#8c8c8c' }}>
						Analysing demo content…
					</p>
				</div>
			)}

			{isUnavailable && dryRunStats && (
				<Alert
					type="info"
					showIcon
					icon={<InfoCircleOutlined />}
					message="Analysis Information"
					description={
						dryRunStats.message ||
						'Detailed item counts will be available once the import starts and files are downloaded.'
					}
					style={{ marginBottom: 20 }}
				/>
			)}

			{dryRunStats && !isUnavailable && (
				<>
					<div
						style={{
							background: '#f9fafb',
							border: '1px solid #f0f0f0',
							borderRadius: 10,
							padding: '20px 24px',
							marginBottom: 24,
						}}
					>
						<Row gutter={24}>
							{pages > 0 && (
								<Col>
									<Statistic
										title="Pages"
										value={pages}
										prefix={<FileTextOutlined />}
									/>
								</Col>
							)}
							{posts > 0 && (
								<Col>
									<Statistic
										title="Posts"
										value={posts}
										prefix={<FileTextOutlined />}
									/>
								</Col>
							)}
							{products > 0 && (
								<Col>
									<Statistic
										title="Products"
										value={products}
										prefix={<AppstoreOutlined />}
									/>
								</Col>
							)}
							{attachments > 0 && (
								<Col>
									<Statistic
										title="Images"
										value={attachments}
										prefix={<PictureOutlined />}
									/>
								</Col>
							)}
							{total > 0 && (
								<Col>
									<Statistic
										title="Total Items"
										value={total}
									/>
								</Col>
							)}
						</Row>
					</div>

					<div
						style={{
							fontSize: 13,
							color: '#595959',
							marginBottom: 8,
						}}
					>
						<strong>Import will include:</strong>{' '}
						{Object.entries(importOptions)
							.filter(([, v]) => v)
							.map(([k]) => k)
							.join(', ')}
					</div>
				</>
			)}

			{back &&
				ReactDOM.createPortal(
					<Button onClick={() => navigate('/wizard/options')}>
						Back
					</Button>,
					back
				)}

			{footer &&
				ReactDOM.createPortal(
					<Button
						type="primary"
						icon={<ThunderboltOutlined />}
						disabled={!dryRunStats}
						onClick={() => navigate('/wizard/importing')}
					>
						Start Import
					</Button>,
					footer
				)}
		</>
	);
};

export default ConfirmationStep;
