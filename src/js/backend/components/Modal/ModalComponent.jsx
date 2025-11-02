import Begin from './steps/Begin';
import Setup from './steps/Setup';
import Imports from './steps/Imports';
import Success from './steps/Success';
import React, { useState } from 'react';
import { doAxios } from '../../utils/Api';
import { Modal, Row, Col, Steps } from 'antd';
import useSharedDataStore from '../../utils/sharedDataStore';
import { getCurrentStatus, progressSteps } from '../../utils/helpers';

/* global sdEdiAdminParams */

/**
 * Component representing a modal dialog.
 *
 * @param {Object}   props           - Component properties.
 * @param {boolean}  props.visible   - Specifies whether the modal is visible.
 * @param {Function} props.onCancel  - Callback function invoked when the modal is canceled.
 * @param {Object}   props.modalData - Data to be displayed in the modal.
 */
const ModalComponent = ({ visible, onCancel, modalData }) => {
	/**
	 * Values from the shared data store.
	 */
	const {
		reset,
		setReset,
		excludeImages,
		setExcludeImages,
		skipImageRegeneration,
		currentStep,
		setCurrentStep,
		importComplete,
		setImportComplete,
		message,
		setMessage,
	} = useSharedDataStore();

	/**
	 * State hooks
	 */
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);
	const [importProgress, setImportProgress] = useState([]);

	/**
	 * Handles the import process.
	 */
	const handleImport = async () => {
		const { id } = modalData;
		let resetMessage = '';
		let confirmMessage = sdEdiAdminParams.confirmationModal;
		let importInitMessage = sdEdiAdminParams.prepareImporting;

		if (reset) {
			resetMessage = sdEdiAdminParams.resetMessage;
			confirmMessage = sdEdiAdminParams.confirmationModalWithReset;
			importInitMessage = sdEdiAdminParams.resetDatabase;
		}

		Modal.confirm({
			title: confirmMessage,
			centered: true,
			className: 'confirmation-modal',
			okText: sdEdiAdminParams.confirmYes,
			cancelText: sdEdiAdminParams.confirmNo,
			onOk: async () => {
				const request = {
					demo: id,
					reset,
					excludeImages,
					skipImageRegeneration,
					nextPhase: 'sd_edi_install_demo',
					nextPhaseMessage: resetMessage,
				};

				try {
					setCurrentStep(3);
					setShowImportProgress(true);

					// Start the import process
					const initialProgress = [{ message: importInitMessage }];
					setImportProgress(initialProgress);

					setTimeout(function () {
						doAxios(
							request,
							setImportProgress,
							setCurrentStep,
							handleImportResponse,
							setMessage
						);
					}, 2000);
				} catch (error) {
					console.error('Error:', error);
					setImportStatus('error');
				}
			},
		});
	};

	/**
	 * Handles the import response and updates the import message.
	 *
	 * @param {Object} response - The response object from the import request.
	 */
	const handleImportResponse = (response) => {
		if (!response.data.error) {
			if (response.data.nextPhase) {
				setImportComplete(false);
			} else {
				setMessage(response.data.completedMessage);
				setImportComplete(true);
			}
		} else {
			setImportComplete(false);
		}
	};

	/**
	 * Progress steps.
	 */
	const steps = progressSteps();

	/**
	 * Handles resetting the modal.
	 */
	const handleReset = () => {
		onCancel();
		setCurrentStep(1);
		setExcludeImages(false);
		setReset(true);
		setImportProgress([]);
		setImportComplete(false);
	};

	return (
		<>
			<Modal
				open={visible}
				closable={false}
				footer={null}
				width={900}
				centered
			>
				{modalData && (
					<Row>
						<Col span={24}>
							<div className="modal-steps">
								<h2>
									{sdEdiAdminParams.modalHeaderPrefix}
									<span>{modalData?.data?.name}</span>
								</h2>
								<Steps
									progressDot
									current={currentStep - 1}
									items={steps.map((step, index) => ({
										title:
											sdEdiAdminParams &&
											sdEdiAdminParams.stepTitles
												? step.title
												: '',
										status: getCurrentStatus(
											currentStep,
											importComplete,
											index
										),
									}))}
								/>
							</div>
							<div
								className={`modal-content step ${
									currentStep === 1 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 1 && (
									<Begin
										handleReset={handleReset}
										modalData={modalData}
									/>
								)}
							</div>
							<div
								className={`modal-content step ${
									currentStep === 2 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 2 && (
									<Setup
										modalData={modalData}
										handleImport={handleImport}
										handleReset={handleReset}
									/>
								)}
							</div>
							<div
								className={`modal-content step import-step ${
									currentStep === 3 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 3 && (
									<Imports
										importStatus={importStatus}
										importProgress={importProgress}
										showImportProgress={showImportProgress}
										handleImport={handleImport}
									/>
								)}
							</div>
							<div
								className={`modal-content step ${
									currentStep === 4 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 4 && (
									<Success
										importComplete={importComplete}
										handleReset={handleReset}
										message={message}
									/>
								)}
							</div>
						</Col>
					</Row>
				)}
			</Modal>
		</>
	);
};

export default ModalComponent;
