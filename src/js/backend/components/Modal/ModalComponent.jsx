import Begin from './steps/Begin';
import Setup from './steps/Setup';
import Imports from './steps/Imports';
import Success from './steps/Success';
import React, { useState } from 'react';
import { doAxios } from '../../utils/Api';
import { Modal, Row, Col, Steps } from 'antd';
import { getCurrentStatus, progressSteps } from '../../utils/helpers';
import useSharedDataStore from '../../utils/sharedDataStore';

const ModalComponent = ({ visible, onCancel, modalData }) => {
	const {
		reset,
		setReset,
		excludeImages,
		setExcludeImages,
		currentStep,
		setCurrentStep,
	} = useSharedDataStore();
	// const [currentStep, setCurrentStep] = useState(1);
	const [importSuccess, setImportSuccess] = useState(false);
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);
	const [importProgress, setImportProgress] = useState([]);
	const [importComplete, setImportComplete] = useState(false);

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
					setCurrentStep(3); // Move to the next step (import progress)
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

	const handlePreview = () => {
		if (modalData && modalData.data && modalData.data.previewUrl) {
			window.open(modalData.data.previewUrl, '_blank');
		}
	};

	const steps = progressSteps();

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
				onCancel={() => {
					handleReset();
				}}
				footer={null}
				width={800}
				bodyStyle={{ height: '500px' }}
			>
				{modalData && (
					<Row>
						<Col span={24}>
							<Steps
								progressDot
								current={currentStep - 1}
								style={{ marginBottom: '20px' }}
								items={steps.map((step, index) => ({
									title: step.title,
									status: getCurrentStatus(
										currentStep,
										importSuccess,
										index
									),
								}))}
							/>
							<div
								className={`modal-content step ${
									currentStep === 1 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 1 && (
									<Begin
									// currentStep={currentStep}
									// setCurrentStep={setCurrentStep}
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
										// currentStep={currentStep}
										// setCurrentStep={setCurrentStep}
										modalData={modalData}
										handleImport={handleImport}
									/>
								)}
							</div>
							<div
								className={`modal-content step ${
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
