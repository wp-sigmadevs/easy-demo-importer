import ModalHeader from '../ModalHeader';
import React, { useEffect } from 'react';
import PluginList from '../../PluginList';
import { Button, Col, Row, Skeleton, Switch, Tooltip } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {
	ArrowLeftOutlined,
	CloseOutlined,
	DownloadOutlined,
	QuestionCircleTwoTone,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the setup step in the modal.
 *
 * @param            modalData.modalData
 * @param {Object}   modalData              - The data for the modal.
 * @param {Function} handleImport           - Function to handle the import process.
 * @param {Function} handleReset            - Handles resetting the modal.
 * @param            modalData.handleImport
 * @param            modalData.handleReset
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
		skipImageRegeneration,
		setSkipImageRegeneration,
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
			<ModalHeader currentStep={currentStep} />

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
							<h3>{sdEdiAdminParams.requiredPluginsTitle}</h3>
							<p>{sdEdiAdminParams.requiredPluginsIntro}</p>
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
						className="gutter-row configure-col"
						xs={24}
						sm={24}
						md={12}
						lg={12}
						xl={12}
					>
						<div className="import-options">
							<h3>{sdEdiAdminParams.configureImportTitle}</h3>
							<div className="import-option new">
								<div className="choose exclude-images edi-d-flex edi-align-items-center edi-pos-r">
									<Switch
										checked={excludeImages}
										onChange={(checked) =>
											setExcludeImages(checked)
										}
									/>
									<h4
										className="edi-d-flex edi-align-items-center"
										style={{ margin: 0 }}
									>
										{sdEdiAdminParams.excludeImagesTitle}
									</h4>
									<Tooltip
										title={
											sdEdiAdminParams.excludeImagesHint
										}
									>
										<span
											style={{
												marginLeft: 8,
												cursor: 'pointer',
												fontSize: 20,
												position: 'absolute',
												right: 0,
											}}
										>
											<QuestionCircleTwoTone />
										</span>
									</Tooltip>
								</div>
							</div>
							{!excludeImages && (
								<div className="import-option new">
									<div className="choose exclude-images edi-d-flex edi-align-items-center edi-pos-r">
										<Switch
											checked={skipImageRegeneration}
											onChange={(checked) =>
												setSkipImageRegeneration(
													checked
												)
											}
										/>
										<h4
											className="edi-d-flex edi-align-items-center"
											style={{ margin: 0 }}
										>
											{
												sdEdiAdminParams.skipImageRegenerationTitle
											}
										</h4>
										<Tooltip
											title={
												sdEdiAdminParams.skipImageRegenerationHint
											}
										>
											<span
												style={{
													marginLeft: 8,
													cursor: 'pointer',
													fontSize: 20,
													position: 'absolute',
													right: 0,
												}}
											>
												<QuestionCircleTwoTone />
											</span>
										</Tooltip>
									</div>
								</div>
							)}
							<div className="import-option last">
								<div className="choose reset-db edi-d-flex edi-align-items-center">
									<Switch
										checked={reset}
										onChange={(checked) =>
											setReset(checked)
										}
									/>
									<h4>
										{sdEdiAdminParams.resetDatabaseTitle}
									</h4>
								</div>
								<div className="option-details warn-text">
									<p>
										<b>
											<i>
												{
													sdEdiAdminParams.resetDatabaseWarning
												}
											</i>
										</b>
										{sdEdiAdminParams.resetDatabaseHint}
									</p>
								</div>
							</div>
						</div>
					</Col>
				</Row>
				<div className="step-actions">
					<div className="actions-left">
						<Button type="primary" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnCancel}</span>
						</Button>
					</div>
					<div className="actions-right edi-d-flex edi-align-items-center">
						{currentStep > 1 && (
							<Button type="primary" onClick={handlePrevious}>
								<ArrowLeftOutlined />
								<span>{sdEdiAdminParams.btnPrevious}</span>
							</Button>
						)}
						<Button type="primary" onClick={handleImport}>
							<span>{sdEdiAdminParams.btnStartImport}</span>
							<DownloadOutlined />
						</Button>
					</div>
				</div>
			</div>
		</>
	);
};

export default Setup;
