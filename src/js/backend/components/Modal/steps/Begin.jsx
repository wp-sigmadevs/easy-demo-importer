import React from 'react';
import { Button } from 'antd';
import ModalHeader from '../ModalHeader';
import useSharedDataStore from '../../../utils/sharedDataStore';
import { ArrowRightOutlined, CloseOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the "Begin" step in the modal.
 *
 * @param {Function} handleReset - Handles resetting the modal.
 */
const Begin = ({ handleReset }) => {
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
			<div className="modal-content-inner">
				<h3>{sdEdiAdminParams.beforeYouPreceed}</h3>
				<div className="import-notice">
					<p>{sdEdiAdminParams.stepOneIntro1}</p>
					<p>{sdEdiAdminParams.stepOneIntro2}</p>
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
