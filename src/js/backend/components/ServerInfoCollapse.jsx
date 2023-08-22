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
													<span>{text}</span>
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
												</span>
											);
										}

										return text;
									},
								},
							]}
							pagination={false}
							rowClassName={(record) =>
								record.error ? 'error-row' : 'row'
							}
						/>
					</Panel>
				);
			})}
		</Collapse>
	);
};

export default ServerInfoCollapse;
