import React, { useState } from 'react';
import { Modal, Button } from 'antd';
import axios from 'axios';

/* global sdEdiAdminParams */

const ModalComponent = ({ visible, onCancel, modalData }) => {
	const [currentStep, setCurrentStep] = useState(1);
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);

	const handleImport = async () => {
		const { id, demo, reset, excludeImages } = modalData;
		let resetMessage = '';
		let confirmMessage = 'Are you sure to proceed?';

		if (reset) {
			resetMessage = sdEdiAdminParams.resetDatabase;
			confirmMessage =
				'Are you sure to proceed? Resetting the database will delete all your contents.';
		}

		const importConfirmed = window.confirm(confirmMessage);

		if (!importConfirmed) {
			return;
		}

		const request = {
			demo: id,
			reset,
			nextPhase: 'sd_edi_install_demo',
			excludeImages,
			nextPhaseMessage: resetMessage,
		};

		try {
			// setCurrentStep(2); // Move to the next step (import progress)

			setTimeout(function () {
				doAxios(request);
			}, 2000);
		} catch (error) {
			console.error('Error:', error);
			// Handle the import error
		}
	};

	const doAxios = async (request) => {
		if (request.nextPhase) {
			// const data = {
			// 	action: request.nextPhase,
			// 	demo: request.id,
			// 	reset: request.reset,
			// 	excludeImages: request.excludeImages,
			// 	sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
			// };

			const params = new FormData();
			params.append('action', request.nextPhase);
			params.append('demo', request.demo);
			params.append('reset', request.reset);
			params.append('excludeImages', request.excludeImages);
			params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);

			const requestUrl = sdEdiAdminParams.ajaxUrl;

			console.log(request)

			try {
				const response = await axios.post(requestUrl, params);

				// if (!response.error) {
				// console.log(response)
				setTimeout(() => {
					doAxios(response.data);
				}, 2000);
				// } else {
				// 	// console.log(data.errorMessage)
				// }
				// Handle the response data here
			} catch (error) {
				console.error('Error:', error);
				// Handle any errors here
			}
		} else {
			console.log(sdEdiAdminParams.importSuccess)
		}
	};

	return (
		<Modal open={visible} onCancel={onCancel} footer={null}>
			{modalData && (
				<>
					<div>
						<img src={modalData.previewImage} alt="Preview" />
						<p>{modalData.previewUrl}</p>
					</div>
					<Button type="primary" onClick={handleImport}>
						Import
					</Button>
				</>
			)}
		</Modal>
	);
};

export default ModalComponent;
