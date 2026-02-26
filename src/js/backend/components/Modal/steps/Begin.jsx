import {
	ArrowRightOutlined,
	CloseOutlined,
	AimOutlined,
} from '@ant-design/icons';
import { Button, Image } from 'antd';
import ModalHeader from '../ModalHeader';
import React, { useState, useEffect, useRef } from 'react';
import useSharedDataStore from '../../../utils/sharedDataStore';

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

	const imageContainerRef = useRef(null);
	const [showTopArrow, setShowTopArrow] = useState(false);
	const [showBottomArrow, setShowBottomArrow] = useState(false);

	/**
	 * Attach scroll listener to the image container and check initial scroll state.
	 */
	useEffect(() => {
		const wrapper = imageContainerRef.current;
		if (!wrapper) return;

		const container = wrapper.querySelector('.ant-image');
		if (!container) return;

		const checkScroll = () => {
			const { scrollTop, scrollHeight, clientHeight } = container;
			setShowTopArrow(scrollTop > 0);
			setShowBottomArrow(scrollTop + clientHeight < scrollHeight - 1);
		};

		// Delay initial check to let the image render and establish scroll height.
		const timer = setTimeout(checkScroll, 300);
		container.addEventListener('scroll', checkScroll);

		return () => {
			clearTimeout(timer);
			container.removeEventListener('scroll', checkScroll);
		};
	}, [modalData]);

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
				<div className="modal-content-image modal-col-6" ref={imageContainerRef}>
					<Image
						src={modalData?.data?.previewImage}
						preview={false}
						alt="Preview"
					/>
					{showTopArrow && <div className="up-arrow"></div>}
					{showBottomArrow && <div className="down-arrow"></div>}
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
