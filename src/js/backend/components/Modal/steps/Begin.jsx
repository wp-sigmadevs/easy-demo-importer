import React from 'react';
import { Button, Image } from 'antd';
import ModalHeader from '../ModalHeader';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {
	ArrowRightOutlined,
	CloseOutlined,
	AimOutlined,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the "Begin" step in the modal.
 *
 * @param {Function} handleReset - Handles resetting the modal.
 * @param {Object}   modalData   - Data for the Modal.
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

	/**
	 * Handle 'Server Page' button behavior.
	 */
	const handleServerPageBtn = () => {
		const serverPageUrl = sdEdiAdminParams.serverPageUrl;
		window.open(serverPageUrl, '_self');
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
					<div className="down-arrow"></div>
				</div>
				<div className="modal-content-text modal-col-6">
					<h3>{sdEdiAdminParams.beforeYouPreceed}</h3>
					<div className="import-notice">
						{sdEdiAdminParams.stepOneIntro1 ? (
							<p
								dangerouslySetInnerHTML={{
									__html: sdEdiAdminParams.stepOneIntro1,
								}}
							></p>
						) : null}
						{sdEdiAdminParams.stepOneIntro2 ? (
							<p
								dangerouslySetInnerHTML={{
									__html: sdEdiAdminParams.stepOneIntro2,
								}}
							></p>
						) : null}
						{sdEdiAdminParams.stepOneIntro3 ? (
							<p
								dangerouslySetInnerHTML={{
									__html: sdEdiAdminParams.stepOneIntro3,
								}}
							></p>
						) : null}
					</div>
				</div>
				<div className="step-actions">
					<Button type="primary" onClick={handleReset}>
						<CloseOutlined />
						<span>{sdEdiAdminParams.btnCancel}</span>
					</Button>
					<div className="actions-right edi-d-flex edi-align-items-center">
						<Button type="primary" onClick={handleServerPageBtn}>
							<span>{sdEdiAdminParams.serverPageBtnText}</span>
							<AimOutlined />
						</Button>
						<Button type="primary" onClick={onNext}>
							<span>{sdEdiAdminParams.btnContinue}</span>
							<ArrowRightOutlined />
						</Button>
					</div>
				</div>
			</div>
		</>
	);
};

export default Begin;
