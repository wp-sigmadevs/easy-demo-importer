import PluginList from '../Plugins';
import ModalHeader from './ModalHeader';
import ImportResult from './ModalResult';
import { doAxios } from '../../utils/Api';
import React, { useEffect, useState } from 'react';
import { ProgressMessage } from '../ProgressMessage';
import useSharedDataStore from '../../utils/sharedDataStore';
import { Modal, Button, Row, Col, Switch, Steps, Timeline } from 'antd';

const ModalComponent = ({ visible, onCancel, modalData }) => {
	const { pluginList, fetchPluginList } = useSharedDataStore();
	const [currentStep, setCurrentStep] = useState(1);
	const [excludeImages, setExcludeImages] = useState(
		modalData?.excludeImages || false
	);
	const [reset, setReset] = useState(modalData?.reset || true);
	const [importSuccess, setImportSuccess] = useState(false);
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);
	const [importProgress, setImportProgress] = useState([]);
	const [importComplete, setImportComplete] = useState(false);
	const [selectedOptions, setSelectedOptions] = useState({
		selectiveImport: false,
		content: true,
		widgets: true,
		customizer: true,
		settings: true,
	});

	useEffect(() => {
		fetchPluginList('/sd/edi/v1/plugin/list');
	}, [fetchPluginList]);

	const handleImport = async () => {
		const { id, demo } = modalData;
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

	const demoPluginData = pluginList.success ? pluginList.data : [];
	const pluginDataArray = Object.entries(demoPluginData).map(
		([key, value]) => ({
			key,
			...value,
		})
	);

	const filteredPluginDataArray =
		Object.keys(modalData?.data?.plugins || {}).length > 0
			? pluginDataArray.filter(
					(plugin) => modalData.data.plugins[plugin.key] !== undefined
			  )
			: pluginDataArray;

	const handlePreview = () => {
		if (modalData && modalData.data && modalData.data.previewUrl) {
			window.open(modalData.data.previewUrl, '_blank');
		}
	};

	const steps = [
		{
			title: 'Begin',
		},
		{
			title: 'Setup',
		},
		{
			title: 'Imports',
		},
		{
			title: 'End',
		},
	];

	const handleReset = () => {
		onCancel();
		setCurrentStep(1);
		setExcludeImages(false); // Reset excludeImages value
		setReset(true); // Reset resetDatabase value
		setImportProgress([]); // Reset import progress
		setImportComplete(false); // Reset import complete status
		setSelectedOptions({
			content: true,
			widgets: true,
			customizer: true,
			settings: true,
			fluentForm: false,
		});
		setImportSuccess(false);
	};

	const getCurrentStatus = (index) => {
		if (index === currentStep - 1) {
			return 'process';
		} else if (index < currentStep) {
			return 'finish';
		} else if (index === 3 && importSuccess) {
			return 'finish';
		}

		return 'wait';
	};

	const renderImportProgress = () => {
		// console.log(importProgress)
		return (
			<Timeline
				items={importProgress.map((progress, index) => ({
					children: (
						<ProgressMessage
							key={index}
							message={progress.message}
							fade={progress.fade}
						/>
					),
					key: index.toString(),
					className:
						index === importProgress.length - 1 ? 'active' : '',
				}))}
			/>
		);
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
									status: getCurrentStatus(index),
								}))}
							/>
							<div
								className={`modal-content step ${
									currentStep === 1 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 1 && (
									<>
										<ModalHeader
											currentStep={currentStep}
										/>
										<div className="notice">
											<p>
												Before importing this demo, we
												recommend that you backup your
												site's data and files. You can
												use a backup plugin like XYZ
												Backup for WordPress to ensure
												you have a copy of your site in
												case anything goes wrong during
												the import process.
											</p>
											<p>
												Please note that this demo
												import will install all the
												required plugins, import
												contents, settings, customizer
												data, widgets, and other
												necessary elements to replicate
												the demo site. Make sure to
												review your existing data and
												settings as they may be
												overwritten.
											</p>
										</div>
										<div className="step-actions">
											<Button
												type="primary"
												onClick={onCancel}
											>
												Cancel
											</Button>
											<Button
												type="primary"
												onClick={() =>
													setCurrentStep(2)
												}
											>
												Next
											</Button>
										</div>
									</>
								)}
							</div>
							<div
								className={`modal-content step ${
									currentStep === 2 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 2 && (
									<>
										<div className="modal-header">
											<h3>
												Step {currentStep}: Configure
												Import Options
											</h3>
											<div className="step-indicator">
												{/* Step Indicator */}
												{[1, 2, 3].map((step) => (
													<div
														key={step}
														className={`step-dot ${
															step === currentStep
																? 'active'
																: ''
														}`}
													/>
												))}
											</div>
										</div>
										<div className="import-options">
											<div>
												<h4>Exclude Images</h4>
												<Switch
													checked={excludeImages}
													onChange={(checked) =>
														setExcludeImages(
															checked
														)
													}
												/>
											</div>
											<div>
												<h4>Reset Database</h4>
												<Switch
													checked={reset}
													onChange={(checked) =>
														setReset(checked)
													}
												/>
											</div>
										</div>
										<div className="required-plugins">
											<PluginList
												plugins={
													filteredPluginDataArray
												}
											/>
										</div>
										<div className="step-actions">
											<Button
												type="primary"
												onClick={() =>
													setCurrentStep(1)
												}
											>
												Previous
											</Button>
											<Button
												type="primary"
												onClick={handleImport}
											>
												Import
											</Button>
										</div>
									</>
								)}
							</div>
							<div
								className={`modal-content step ${
									currentStep === 3 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 3 && (
									<>
										<div
											className={`import-progress ${importStatus}`}
										>
											{showImportProgress ? (
												renderImportProgress()
											) : (
												<>
													<Button
														type="primary"
														onClick={handleImport}
													>
														Import
													</Button>
												</>
											)}
										</div>
									</>
								)}
							</div>
							<div
								className={`modal-content step ${
									currentStep === 4 ? 'fade-in' : 'fade-out'
								}`}
							>
								{currentStep === 4 && (
									<ImportResult
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
