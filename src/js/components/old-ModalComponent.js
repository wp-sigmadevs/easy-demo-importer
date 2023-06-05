import React, { useEffect, useState } from 'react';
import { Modal, Button, Row, Col } from 'antd';
import { usePluginListStore } from '../utils/pluginListStore';
import PluginList from './Plugins';
import { doAxios } from '../utils/Api';

/* global sdEdiAdminParams */

const OldModalComponent = ({ visible, onCancel, modalData, pluginData }) => {
	const { pluginList, fetchPluginList } = usePluginListStore();
	const [currentStep, setCurrentStep] = useState(1);
	const [importStatus, setImportStatus] = useState('');
	const [showImportProgress, setShowImportProgress] = useState(false);

	useEffect(() => {
		fetchPluginList('/sd/edi/v1/plugin/list');
	}, [fetchPluginList]);

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

	// console.log(modalData)

	const handlePreview = () => {
		if (modalData && modalData.data && modalData.data.previewUrl) {
			window.open(modalData.data.previewUrl, '_blank');
		}
	};

	return (
		<>
			<Modal
				open={visible}
				onCancel={onCancel}
				footer={null}
				width={800}
				bodyStyle={{ height: '500px' }}
			>
				{modalData && (
					<Row gutter={30}>
						<Col span={12}>
							<img
								src={modalData.data.previewImage}
								alt="Preview"
							/>
							<Button type="primary" onClick={handlePreview}>
								Preview
							</Button>
						</Col>
						<Col span={12}>
							<div className="modal-header">
								<h3>Before You Proceed</h3>
							</div>
							<div className="modal-content">
								<div className="notice">
									<p>
										Before importing this demo, we recommend
										that you backup your site's data and
										files. You can use a backup plugin like
										XYZ Backup for WordPress to ensure you
										have a copy of your site in case
										anything goes wrong during the import
										process.
									</p>
									<p>
										Please note that this demo import will
										install all the required plugins, import
										contents, settings, customizer data,
										widgets, and other necessary elements to
										replicate the demo site. Make sure to
										review your existing data and settings
										as they may be overwritten.
									</p>
								</div>
								<div className="required-plugins">
									<PluginList
										plugins={filteredPluginDataArray}
									/>
								</div>
							</div>

							<Button type="primary" onClick={onCancel}>
								Cancel
							</Button>
							<Button type="primary" onClick={handlePreview}>
								Preview
							</Button>
							<Button type="primary" onClick={handleImport}>
								Import
							</Button>
						</Col>
					</Row>
				)}
			</Modal>
		</>
	);
};

export default OldModalComponent;
