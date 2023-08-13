import React from 'react';
import {Button, Image} from 'antd';
import ModalHeader from '../ModalHeader';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {ArrowRightOutlined, CloseOutlined, FullscreenOutlined} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the "Begin" step in the modal.
 *
 * @param {Function} handleReset - Handles resetting the modal.
 */
const Begin = ({ handleReset, modalData }) => {
	/**
	 * Values from the shared data store.
	 */
	const { currentStep, setCurrentStep } = useSharedDataStore();

	/**
	 * Handles moving to the next step.
	 */
	const onNext = () => {
		setCurrentStep(currentStep + 1);
	};

	return (
		<>
			<ModalHeader currentStep={currentStep} />
			<div className="modal-content-inner modal-row">
				<div className="modal-content-image modal-col-6">
					<Image
						src={modalData?.data?.previewImage}
						preview={false}
						alt="Preview"
					/>
				</div>
				<div className="modal-content-text modal-col-6">
					<h3>{sdEdiAdminParams.beforeYouPreceed}</h3>
					<div className="import-notice">
						<p>{sdEdiAdminParams.stepOneIntro1}</p>
						<p>{sdEdiAdminParams.stepOneIntro2}</p>
					</div>
				</div>
				<div className="step-actions">
					<Button type="primary" onClick={handleReset}>
						<CloseOutlined />
						<span>{sdEdiAdminParams.btnCancel}</span>
					</Button>
					<Button type="primary" onClick={onNext}>
						<span>{sdEdiAdminParams.btnContinue}</span>
						<ArrowRightOutlined />
					</Button>
				</div>
			</div>
		</>
	);
};

export default Begin;
