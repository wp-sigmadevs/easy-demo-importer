import {
	CommentOutlined,
	ExportOutlined,
	QuestionOutlined,
	MailOutlined,
	QuestionCircleOutlined,
} from '@ant-design/icons';
import React, { useState } from 'react';
import { Button, FloatButton, Modal } from 'antd';

/* global sdEdiAdminParams */

const Support = () => {
	const [modalVisible, setModalVisible] = useState(false);
	const [activeButton, setActiveButton] = useState('');

	const handleButtonClick = (buttonName) => {
		setModalVisible(true);
		setActiveButton(buttonName);
	};

	const handleModalClose = () => {
		setModalVisible(false);
		setActiveButton('');
	};

	const handleClick = (url) => {
		window.open(url, '_blank');
	};

	return (
		<>
			<FloatButton.Group
				trigger="click"
				type="primary"
				style={{ right: 50 }}
				icon={<QuestionOutlined />}
			>
				<FloatButton
					tooltip={<div>{sdEdiAdminParams.onlineDoc}</div>}
					onClick={() =>
						handleButtonClick(sdEdiAdminParams.onlineDoc)
					}
				>
					{sdEdiAdminParams.onlineDoc}
				</FloatButton>
				<FloatButton
					icon={<CommentOutlined />}
					tooltip={<div>{sdEdiAdminParams.needHelp}</div>}
					onClick={() => handleButtonClick(sdEdiAdminParams.needHelp)}
				>
					{sdEdiAdminParams.needHelp}
				</FloatButton>
			</FloatButton.Group>

			<Modal
				open={modalVisible}
				onCancel={handleModalClose}
				footer={null}
				centered={true}
				className="support-modal"
			>
				<>
					{activeButton === sdEdiAdminParams.onlineDoc && (
						<div className="support doc">
							<div className="support-modal-header">
								<h2>
									<QuestionCircleOutlined />
									<span>{sdEdiAdminParams.docTitle}</span>
								</h2>
							</div>
							<div className="support-modal-content">
								<p>{sdEdiAdminParams.docDesc}</p>
							</div>
							<div className="support-modal-footer">
								<Button
									key="view-doc"
									type="primary"
									onClick={() =>
										handleClick(sdEdiAdminParams.docUrl)
									}
								>
									<span>
										{sdEdiAdminParams.viewDocumentation}
									</span>
									<ExportOutlined />
								</Button>
							</div>
						</div>
					)}
					{activeButton === sdEdiAdminParams.needHelp && (
						<div className="support help">
							<div className="support-modal-header">
								<h2>
									<MailOutlined />
									<span>{sdEdiAdminParams.supportTitle}</span>
								</h2>
							</div>
							<div className="support-modal-content">
								<p>{sdEdiAdminParams.supportDesc}</p>
							</div>
							<div className="support-modal-footer">
								<Button
									key="view-site"
									type="primary"
									onClick={() =>
										handleClick(sdEdiAdminParams.ticketUrl)
									}
								>
									<span>
										{sdEdiAdminParams.createATicket}
									</span>
									<ExportOutlined />
								</Button>
							</div>
						</div>
					)}
				</>
			</Modal>
		</>
	);
};

export default Support;
