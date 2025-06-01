/**
 * ServerInfoCollapse component displays server information in a collapsible format.
 * It allows users to view detailed server status and copy system data to the clipboard.
 *
 * @param {Object} serverInfo - The server information to display.
 */

import React, { useState, useRef } from 'react';
import { Collapse, Table, Popover, Alert, message, Button } from 'antd';
import { InfoCircleOutlined, CopyOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

const ServerInfoCollapse = ({ serverInfo }) => {
	const [activePanel, setActivePanel] = useState('');
	const textareaRef = useRef(null);

	/**
	 * Handles the change of the active accordion panel.
	 *
	 * @param {string} key - The key of the active panel.
	 */
	const handleAccordionChange = (key) => {
		setActivePanel(key);
		setTimeout(() => {
			scrollToPanel(key);
		}, 300);
	};

	/**
	 * Scrolls the page to the specified panel.
	 *
	 * @param {string} panelKey - The key of the panel to scroll to.
	 */
	const scrollToPanel = (panelKey) => {
		const panelElement = document.querySelector(
			`[data-panel-key="${panelKey}"]`
		);

		if (!panelElement) {
			return;
		}

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

	/**
	 * Generates a report string from the server information.
	 *
	 * @param {Object} info - The server information object.
	 */
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

	/**
	 * Copies the content of the textarea to the clipboard.
	 */
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

	/**
	 * Fallback method to copy text to the clipboard using a temporary textarea.
	 *
	 * @param {string} text - The text to copy to the clipboard.
	 */
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

	/**
	 * Displays a message indicating the result of the copy action.
	 *
	 * @param {string} content - The message content to display.
	 */
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

	/**
	 * Generates the header for each panel based on server item information.
	 *
	 * @param {Object} serverItem - The server item object containing fields and labels.
	 */
	const getPanelHeader = (serverItem) => {
		const errorCount = Object.keys(serverItem.fields).filter(
			(fieldKey) => serverItem.fields[fieldKey].error
		).length;

		return (
			<div data-panel-key={serverItem.id}>
				<span>{serverItem.label}</span>
				{errorCount > 0 && (
					<span className="error-count">{errorCount}</span>
				)}
			</div>
		);
	};

	// Prepare the items for the Collapse component.
	const panels = Object.keys(serverInfo).map((key) => {
		const serverItem = {
			...serverInfo[key],
			id: key,
		};

		return {
			key: serverItem.id,
			label: getPanelHeader(serverItem),
			children:
				serverItem.id === 'copy_system_data' ? (
					<div className="demo-importer-status-report">
						<div id="system-status-report">
							{Object.keys(serverItem.fields).map((fieldKey) => (
								<div className="report-inner" key={fieldKey}>
									<p>{serverItem.fields[fieldKey].label}</p>
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
											onClick={handleCopyToClipboard}
										>
											<span>
												{
													serverItem.fields[fieldKey]
														.value
												}
											</span>
											<CopyOutlined />
										</Button>
									</p>
								</div>
							))}
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
															marginLeft: '4px',
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
															message="Info"
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
															marginLeft: '4px',
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
				),
		};
	});

	return (
		<Collapse
			accordion
			expandIconPosition="end"
			onChange={handleAccordionChange}
			activeKey={activePanel}
			items={panels}
		/>
	);
};

export default ServerInfoCollapse;
