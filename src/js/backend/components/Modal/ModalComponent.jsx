import Begin from './steps/Begin';
import Setup from './steps/Setup';
import Imports from './steps/Imports';
import Success from './steps/Success';
import React, { useState } from 'react';
import { doAxios } from '../../utils/Api';
import { Modal, Row, Col, Steps } from 'antd';
import useSharedDataStore from '../../utils/sharedDataStore';
import { getCurrentStatus, progressSteps } from '../../utils/helpers';

/**
 * Component representing the Modal.
 *
 * @param {boolean}  visible   - Specifies whether the Modal is visible.
 * @param {Function} onCancel  - Callback function invoked when the Modal is canceled.
 * @param {Object}   modalData - Data for the Modal.
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
		currentStep,
		setCurrentStep,
	} = useSharedDataStore();

	/**
	 * State hooks
	 */
	const [importSuccess, setImportSuccess] = useState(false);
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);
	const [importProgress, setImportProgress] = useState([]);
	const [importComplete, setImportComplete] = useState(false);

	/**
	 * Handles the import process.
	 */
	const handleImport = async () => {
		const { id } = modalData;
		let resetMessage = '';
		let confirmMessage = 'Are you sure you want to proceed?';

		if (reset) {
			resetMessage =
				'Resetting the database will delete all your contents.';
			confirmMessage =
				'Are you sure you want to proceed? Resetting the database will delete all your contents.';
		}

		Modal.confirm({
			title: confirmMessage,
			centered: true,
			className: 'confirmation-modal',
			okText: 'Yes',
			cancelText: 'No',
			onOk: async () => {
				const request = {
					demo: id,
					reset,
					excludeImages,
					nextPhase: 'sd_edi_install_demo',
					nextPhaseMessage: resetMessage,
				};

				try {
					setCurrentStep(3);
					setShowImportProgress(true);

					// Start the import process
					const initialProgress = [{ message: 'Importing...' }];
					setImportProgress(initialProgress);

					setTimeout(function () {
						doAxios(
							request,
							setImportProgress,
							setImportComplete,
							setCurrentStep
						);
					}, 2000);

					setImportComplete(true);
					setImportSuccess(true);
				} catch (error) {
					console.error('Error:', error);
					// Handle the import error
					setImportStatus('error');
				}
			},
		});
	};

	/**
	 * Handles previewing the modal data.
	 */
	const handlePreview = () => {
		if (modalData && modalData.data && modalData.data.previewUrl) {
			window.open(modalData.data.previewUrl, '_blank');
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
		setImportSuccess(false);
	};

	return (
		<>
			<Modal
				open={visible}
				closable={false}
				footer={null}
				width={900}
				centered
				// bodyStyle={{ height: '600px' }}
			>
				{modalData && (
					<Row>
						<Col span={24}>
							<div className="modal-steps">
								<h2>
									Importing Demo:
									<span>{modalData?.data?.name}</span>
								</h2>
								<Steps
									progressDot
									current={currentStep - 1}
									items={steps.map((step, index) => ({
										status: getCurrentStatus(
											currentStep,
											importSuccess,
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
									<Begin handleReset={handleReset} />
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
										importSuccess={importSuccess}
										handleReset={handleReset}
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
