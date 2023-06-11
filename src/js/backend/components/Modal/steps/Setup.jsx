import ModalHeader from '../ModalHeader';
import React, { useEffect } from 'react';
import PluginList from '../../PluginList';
import { Button, Col, Row, Skeleton, Switch } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {ArrowLeftOutlined, CloseOutlined, DownloadOutlined} from '@ant-design/icons';

/**
 * Component representing the setup step in the modal.
 *
 * @param {Object}   modalData    - The data for the modal.
 * @param {Function} handleImport - Function to handle the import process.
 * @param {Function} handleReset  - Handles resetting the modal.
 */
const Setup = ({ modalData, handleImport, handleReset }) => {
	/**
	 * Values from the shared data store.
	 */
	const {
		excludeImages,
		setExcludeImages,
		reset,
		setReset,
		pluginList,
		fetchPluginList,
		loading,
		setLoading,
		currentStep,
		setCurrentStep,
	} = useSharedDataStore();

	/**
	 * Sets the loading state to true.
	 */
	useEffect(() => {
		setLoading(true);
	}, [setLoading]);

	/**
	 * Fetches the plugin list from the server.
	 */
	useEffect(() => {
		(async () => {
			try {
				await fetchPluginList('/sd/edi/v1/plugin/list');
			} catch (error) {
				console.error(error);
			}
		})();
	}, [fetchPluginList]);

	const demoPluginData = pluginList.success ? pluginList.data : [];

	/**
	 * Array of plugin data objects.
	 */
	const pluginDataArray = Object.entries(demoPluginData).map(
		([key, value]) => ({
			key,
			...value,
		})
	);

	/**
	 * Filtered array of plugin data objects based on modal data.
	 */
	const filteredPluginDataArray =
		Object.keys(modalData?.data?.plugins || {}).length > 0
			? pluginDataArray.filter(
					(plugin) => modalData.data.plugins[plugin.key] !== undefined
			  )
			: pluginDataArray;

	/**
	 * Function to handle the previous step action.
	 */
	const handlePrevious = () => {
		setCurrentStep(currentStep - 1);
	};

	return (
		<>
			<ModalHeader
				currentStep={currentStep}
				title="Configure your import"
			/>
			<div className="modal-content-inner">
				<Row gutter={[30, 30]}>
					<Col
						className="gutter-row"
						xs={24}
						sm={24}
						md={12}
						lg={12}
						xl={12}
					>
						<div className="required-plugins">
							<h3>Required Plugins</h3>
							<p>
								In order to replicate the exact appearance of
								the demo site, the import process will
								automatically install and activate the following
								plugins, provided they are not already installed
								or activated on your website. You may need to
								scroll through to see the full list:
							</p>
							{loading ? (
								<div className="skeleton-list">
									<Skeleton
										active
										avatar
										paragraph={{ rows: 1, width: '25%' }}
										style={{
											borderBottom:
												'1px solid rgba(5, 5, 5, 0.06)',
										}}
									/>
									<Skeleton
										active
										avatar
										paragraph={{ rows: 1, width: '25%' }}
									/>
								</div>
							) : (
								<PluginList plugins={filteredPluginDataArray} />
							)}
						</div>
					</Col>
					<Col
						className="gutter-row"
						xs={24}
						sm={24}
						md={12}
						lg={12}
						xl={12}
					>
						<div className="import-options">
							<h3>Configure Your Import</h3>
							<div className="import-option">
								<div className="choose exclude-images edi-d-flex edi-align-items-center">
									<Switch
										checked={excludeImages}
										onChange={(checked) =>
											setExcludeImages(checked)
										}
									/>
									<h4>Exclude Demo Images</h4>
								</div>
								<div className="option-details">
									<p>
										Select this option if demo import fails
										repeatedly. Excluding images speeds up
										the import process.
									</p>
								</div>
							</div>
							<div>
								<div className="import-option">
									<div className="choose reset-db edi-d-flex edi-align-items-center">
										<Switch
											checked={reset}
											onChange={(checked) =>
												setReset(checked)
											}
										/>
										<h4>Reset Exisiting Database</h4>
									</div>
									<div className="option-details warn-text">
										<p>
											<b>
												<i>Caution</i>
											</b>
											: Resetting the database will erase
											all of your content, including
											posts, pages, images, custom post
											types, taxonomies and settings. It
											is advised to reset the database for
											a full demo import.
										</p>
									</div>
								</div>
							</div>
						</div>
					</Col>
				</Row>
				<div className="step-actions">
					<div className="actions-left">
						<Button type="primary" onClick={handleReset}>
							<CloseOutlined />
							<span>Cancel</span>
						</Button>
					</div>
					<div className="actions-right edi-d-flex edi-align-items-center">
						{currentStep > 1 && (
							<Button type="primary" onClick={handlePrevious}>
								<ArrowLeftOutlined />
								<span>Previous</span>
							</Button>
						)}
						<Button type="primary" onClick={handleImport}>
							<span>Start Import</span>
							<DownloadOutlined />
						</Button>
					</div>
				</div>
			</div>
		</>
	);
};

export default Setup;
