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
			id,
			reset,
			nextPhase: 'sd_edi_install_demo',
			excludeImages,
			nextPhaseMessage: resetMessage,
		};

		try {
			// setCurrentStep(2); // Move to the next step (import progress)

			setTimeout(function () {
				doFetch(request);
			}, 2000);
		} catch (error) {
			console.error('Error:', error);
			// Handle the import error
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

async function doFetch(info) {
	if (info.nextPhase) {
		const data = {
			action: info.nextPhase,
			demo: info.demo,
			reset: info.reset,
			excludeImages: info.excludeImages,
			sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
		};

		axios.post(sdEdiAdminParams.ajaxurl, data)
			.then(response => {
				console.log(response)
				const info = JSON.parse(response.data);

				if (!info.error) {

				}
			})
			.catch(error => {
				console.log(error)
			});


		// try {
		// 	const response = await fetch(sdEdiAdminParams.ajaxUrl, {
		// 		method: 'POST',
		// 		headers: {
		// 			'Content-Type': 'application/json',
		// 		},
		// 		body: JSON.stringify(data),
		// 	});
		//
		// 	if (!response.ok) {
		// 		throw new Error('Network response was not ok');
		// 	}
		//
		// 	const responseData = await response.json();
		//
		// 	if (!responseData.error) {
		// 		if (responseData.completedMessage) {
		// 			// 	const importProgressMessage = document.querySelector(
		// 			// 		'#rtdi-import-progress .rtdi-import-progress-message'
		// 			// 	);
		// 			// 	importProgressMessage.style.display = 'none';
		// 			// 	importProgressMessage.innerHTML = '';
		// 			// 	importProgressMessage.style.display = 'block';
		// 			// 	importProgressMessage.innerHTML =
		// 			// 		responseData.completedMessage;
		// 			// }
		// 			setTimeout(function () {
		// 				doFetch(responseData);
		// 			}, 2000);
		// 		}
		// 	}
		// } catch (error) {
		// }
	}
}

export default ModalComponent;
