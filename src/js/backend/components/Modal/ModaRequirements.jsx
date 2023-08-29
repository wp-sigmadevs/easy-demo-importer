import React from 'react';
import { Modal, Button } from 'antd';
import { InfoCircleOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the requirement error modal.
 *
 * @param {Object}   props           - Component properties.
 * @param {boolean}  props.isVisible - Whether the modal is visible.
 * @param {Function} props.onClose   - Function to close the modal.
 * @param {Function} props.onProceed - Function to proceed with the required action.
 */
const ModalRequirements = ({ isVisible, onClose, onProceed }) => {
	return (
		<Modal
			open={isVisible}
			footer={null}
			closable={false}
			className="requirement-modal"
			width={700}
			centered
		>
			<div className="edi-no-server-req">
				<div className="modal-steps">
					<h2>
						<InfoCircleOutlined />
						<span>{sdEdiAdminParams.reqHeader}</span>
					</h2>
				</div>
				<div className="modal-content-inner">
					<div className="edi-no-req-msg">
						{sdEdiAdminParams.reqDescription}
					</div>
					<div className="step-actions">
						<Button type="primary" onClick={onProceed}>
							<span>{sdEdiAdminParams.serverPageBtnText}</span>
						</Button>
						<Button type="primary" onClick={onClose}>
							<span>{sdEdiAdminParams.reqProceed}</span>
						</Button>
					</div>
				</div>
			</div>
		</Modal>
	);
};

export default ModalRequirements;
