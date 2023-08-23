import React, { useState } from 'react';
import { Collapse, Table, Popover, Alert } from 'antd';
import { InfoCircleOutlined } from '@ant-design/icons';

const ServerInfoCollapse = ({ serverInfo }) => {
	const { Panel } = Collapse;
	const [activePanel, setActivePanel] = useState('');

	const handleAccordionChange = (key) => {
		setActivePanel(key);

		setTimeout(() => {
			scrollToPanel(key);
		}, 300);
	};

	const scrollToPanel = (panelKey) => {
		const panelElement = document.querySelector(
			`[data-panel-key="${panelKey}"]`
		);
		const offset = 140;
		const panelPosition = panelElement.getBoundingClientRect();
		const currentScrollY = window.scrollY;
		const scrollToPosition = panelPosition.top + currentScrollY - offset;

		if (panelElement) {
			window.scrollTo({
				top: scrollToPosition,
				behavior: 'smooth',
			});
		}
	};

	const getPanelHeader = (serverItem) => {
		const errorCount = Object.keys(serverItem.fields).filter(
			(fieldKey) => serverItem.fields[fieldKey].error
		).length;

		let errorText = 'Issue detected';

		if (errorCount === 0) {
			return (
				<div data-panel-key={serverItem.id}>
					<span>{serverItem.label}</span>
				</div>
			);
		}

		if (errorCount > 1) {
			errorText = 'Issues detected';
		}

		const headerText = `${errorCount} ${errorText}`;

		return (
			<div data-panel-key={serverItem.id}>
				<span>{serverItem.label}</span>
				{headerText && (
					<span className="error-count">{headerText}</span>
				)}
			</div>
		);
	};

	return (
		<Collapse
			accordion
			expandIconPosition="end"
			onChange={handleAccordionChange}
			activeKey={activePanel}
		>
			{Object.keys(serverInfo).map((key) => {
				const serverItem = {
					...serverInfo[key],
					id: key,
				};

				return (
					<Panel
						key={serverItem.id}
						header={getPanelHeader(serverItem)}
					>
						{serverItem.id === 'copy_system_data' ? (
							<div className="demo-importer-status-report">
								<div id="system-status-report">
									{Object.keys(serverItem.fields).map(
										(fieldKey) => (
											<div
												className="report-inner"
												key={fieldKey}
											>
												<p>
													{
														serverItem.fields[
															fieldKey
														].label
													}
												</p>
												<textarea readOnly="readonly"></textarea>
												<p className="submit">
													<button className="button-primary">
														{
															serverItem.fields[
																fieldKey
															].value
														}
													</button>
												</p>
											</div>
										)
									)}
								</div>
							</div>
						) : (
							<Table
								dataSource={Object.keys(serverItem.fields).map(
									(fieldKey) => ({
										...serverItem.fields[fieldKey],
										key: fieldKey,
									})
								)}
								columns={[
									{
										dataIndex: 'label',
										key: 'label',
									},
									{
										dataIndex: 'value',
										key: 'value',
										render: (text, record) => {
											if (record.error) {
												return (
													<span className="error-value">
														<Popover
															content={
																<Alert
																	message="Error"
																	description={
																		record.error
																	}
																	type="error"
																	showIcon
																/>
															}
														>
															<InfoCircleOutlined
																style={{
																	marginLeft:
																		'4px',
																}}
															/>
														</Popover>
														<span>{text}</span>
													</span>
												);
											}

											if (record.info) {
												return (
													<span className="info-value">
														<Popover
															content={
																<Alert
																	message="Error"
																	description={
																		record.info
																	}
																	type="info"
																	showIcon
																/>
															}
														>
															<InfoCircleOutlined
																style={{
																	marginLeft:
																		'4px',
																}}
															/>
														</Popover>
														<span>{text}</span>
													</span>
												);
											}

											return text;
										},
									},
								]}
								pagination={false}
								rowClassName={(record) => {
									if (record?.error) {
										return 'error-row';
									}

									if (record?.info) {
										return 'info-row';
									}

									return 'row';
								}}
							/>
						)}
					</Panel>
				);
			})}
		</Collapse>
	);
};

export default ServerInfoCollapse;
