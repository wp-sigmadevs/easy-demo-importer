import React, { useState, useRef } from 'react';
import { Collapse, Table, Popover, Alert, message, Button } from 'antd';
import { InfoCircleOutlined, CopyOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

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

	const generateReport = (info) => {
		const sections = Object.keys(info);

		let report = '';

		sections.forEach((sectionKey) => {
			if (sectionKey !== 'copy_system_data') {
				const section = info[sectionKey];

				if (section && section.fields) {
					report += `== ${section.label} ==\n`;

					Object.keys(section.fields).forEach((fieldKey) => {
						const field = section.fields[fieldKey];
						report += `\t${field.label}: ${field.value}\n`;
					});

					report += '\n';
				}
			}
		});

		return report;
	};

	const report = generateReport(serverInfo);
	const textareaRef = useRef(null);

	const handleCopyToClipboard = () => {
		if (textareaRef.current) {
			const textToCopy = textareaRef.current.value;

			if (window.isSecureContext && navigator.clipboard) {
				navigator.clipboard
					.writeText(textToCopy)
					.then(() => {
						showCopyMessage(sdEdiAdminParams.copySuccess);
					})
					.catch((error) => {
						console.error(sdEdiAdminParams.copyFailure, error);

						fallbackCopyToClipboard(textToCopy);
					});
			} else {
				fallbackCopyToClipboard(textToCopy);
			}
		}
	};

	const fallbackCopyToClipboard = (text) => {
		const textArea = document.createElement('textarea');
		textArea.value = text;
		document.body.appendChild(textArea);
		textArea.select();

		try {
			document.execCommand('copy');
			showCopyMessage(sdEdiAdminParams.copySuccess);
		} catch (error) {
			console.error(sdEdiAdminParams.copyFailure, error);
			showCopyMessage(sdEdiAdminParams.copyFailure);
		} finally {
			document.body.removeChild(textArea);
		}
	};

	const showCopyMessage = (content) => {
		const key = 'copyMessage';

		textareaRef.current.focus();
		textareaRef.current.select();

		message.open({
			key,
			type: 'loading',
			content: 'Loading...',
			style: {
				marginTop: '40vh',
			},
		});

		setTimeout(() => {
			message.success({
				key,
				content,
				duration: 3,
				style: {
					marginTop: '40vh',
				},
			});
		}, 1000);
	};

	const getPanelHeader = (serverItem) => {
		const errorCount = Object.keys(serverItem.fields).filter(
			(fieldKey) => serverItem.fields[fieldKey].error
		).length;

		if (errorCount === 0) {
			return (
				<div data-panel-key={serverItem.id}>
					<span>{serverItem.label}</span>
				</div>
			);
		}

		const headerText = `${errorCount}`;

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
												<textarea
													ref={textareaRef}
													readOnly
													rows={20}
													value={report}
												/>
												<p className="submit">
													<Button
														className="edi-copy-button"
														type="primary"
														onClick={
															handleCopyToClipboard
														}
													>
														<span>
															{
																serverItem
																	.fields[
																	fieldKey
																].value
															}
														</span>
														<CopyOutlined />
													</Button>
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
