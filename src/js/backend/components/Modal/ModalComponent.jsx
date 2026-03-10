import Begin from './steps/Begin';
import Setup from './steps/Setup';
import Imports from './steps/Imports';
import Success from './steps/Success';
import React, { useState, useEffect } from 'react';
import Axios from 'axios';
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
		currentStep,
		setCurrentStep,
		importComplete,
		setImportComplete,
		message,
		setMessage,
		hint,
		setHint,
		resumeRequest,
		setResumeRequest,
		activeSessionId,
		setActiveSessionId,
	} = useSharedDataStore();

	/**
	 * State hooks
	 */
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);
	const [importProgress, setImportProgress] = useState([]);

	/**
	 * When the modal opens and there is a saved resume request (from a previous interrupted
	 * import), skip straight to the result step so the Resume / Start Over buttons are
	 * immediately visible.  The user should never have to click through Begin → Setup just
	 * to discover the resume option.
	 */
	useEffect(() => {
		if (visible && resumeRequest && resumeRequest.demo === modalData?.id) {
			setMessage(
				sdEdiAdminParams.importInterruptedTitle || 'Import was interrupted.'
			);
			setHint(
				sdEdiAdminParams.importInterruptedHint ||
					'Your previous import did not complete. Resume from where it left off, or start over to begin fresh.'
			);
			setCurrentStep(4);
		}
	}, [visible]); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Fire-and-forget AJAX call to release the import session lock.
	 * Used when the user closes the modal or clicks "Start Over".
	 *
	 * @param {string} sessionId - The session ID to release, or empty to force-release any lock.
	 */
	const releaseLock = (sessionId = '') => {
		const params = new FormData();
		params.append('action', 'sd_edi_cancel_session');
		params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
		// Bootstrap requires 'demo' in POST to load the Ajax\Backend service classes.
		params.append('demo', modalData?.id || '');

		if (sessionId) {
			params.append('sessionId', sessionId);
		}

		Axios.post(sdEdiAdminParams.ajaxUrl, params).catch(() => {
			// Silently ignore — lock will expire automatically after 30 minutes.
		});
	};

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
							setMessage,
							setHint,
							setResumeRequest
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
	 * Also tracks the active session ID so we can release the lock on modal close.
	 *
	 * @param {Object} response - The response object from the import request.
	 */
	const handleImportResponse = (response) => {
		// Track the session ID from every step so handleReset can release the lock.
		if (response.data.sessionId) {
			setActiveSessionId(response.data.sessionId);
		}

		if (!response.data.error) {
			if (response.data.nextPhase) {
				setImportComplete(false);
			} else {
				setMessage(response.data.completedMessage);
				setImportComplete(true);
				// Import finished cleanly — clear session tracking and any saved resume state.
				setActiveSessionId('');
				setResumeRequest(null);
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
	 * If an import session is active, releases the lock so the next attempt isn't blocked.
	 */
	const handleReset = () => {
		if (activeSessionId) {
			// An import was started in this page session — release the lock and clear the
			// saved resume state so reopening starts fresh.
			releaseLock(activeSessionId);
			setActiveSessionId('');
			setResumeRequest(null);
		}
		// If there is no activeSessionId the user is dismissing the "interrupted" prompt
		// without starting a new import.  Keep resumeRequest intact so the prompt appears
		// again the next time this demo's modal is opened.

		onCancel();
		setCurrentStep(1);
		setExcludeImages(false);
		setReset(true);
		setImportProgress([]);
		setImportComplete(false);
	};

	/**
	 * Resume the import from the step that failed.
	 * The session lock is still held, so no re-initialisation needed.
	 */
	const handleResume = () => {
		if (!resumeRequest) return;

		setCurrentStep(3);
		setShowImportProgress(true);
		setImportProgress([{ message: sdEdiAdminParams.resumingImport || 'Resuming import...' }]);

		doAxios(
			resumeRequest,
			setImportProgress,
			setCurrentStep,
			handleImportResponse,
			setMessage,
			setHint,
			setResumeRequest
		);
	};

	/**
	 * Release the session lock and reload so the user can start a completely fresh import.
	 */
	const handleStartOver = () => {
		// Use the active session ID if available, otherwise force-release any lock.
		const sessionId = activeSessionId || resumeRequest?.sessionId || '';
		const params = new FormData();

		params.append('action', 'sd_edi_cancel_session');
		params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
		// Bootstrap requires 'demo' in POST to load the Ajax\Backend service classes.
		params.append('demo', modalData?.id || '');

		if (sessionId) {
			params.append('sessionId', sessionId);
		}

		// Clear saved resume state — user wants a fresh start.
		setResumeRequest(null);

		// Wait for lock release before reloading so the next import attempt is clean.
		Axios.post(sdEdiAdminParams.ajaxUrl, params).finally(() => {
			window.location.reload();
		});
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
										handleResume={handleResume}
										handleStartOver={handleStartOver}
										canResume={!!resumeRequest}
										message={message}
										hint={hint}
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
