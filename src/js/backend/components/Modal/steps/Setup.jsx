import ModalHeader from '../ModalHeader';
import React from 'react';
import { Button, Col, Row, Switch, Tooltip } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {
	ArrowLeftOutlined,
	CloseOutlined,
	DownloadOutlined,
	QuestionCircleTwoTone,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Configure step: the import options only. The required-plugins list and the
 * environment checklist moved to the preceding Readiness step, so this step is
 * just the user's choices, laid out in two columns — media options on the left,
 * safety options (restore point, reset) on the right.
 *
 * @param {Object}   props              - Component props.
 * @param {Function} props.handleImport - Starts the import process.
 * @param {Function} props.handleReset  - Closes/resets the modal.
 */
const Setup = ({ handleImport, handleReset }) => {
	const {
		excludeImages,
		setExcludeImages,
		reset,
		setReset,
		currentStep,
		setCurrentStep,
		skipImageRegeneration,
		setSkipImageRegeneration,
		snapshot,
		setSnapshot,
		preflightData,
	} = useSharedDataStore();

	/**
	 * Whether a blocking readiness check failed. The checklist itself lives on the
	 * Readiness step; here we only read the result to keep Start Import gated.
	 */
	const preflight = preflightData?.success && preflightData.data;
	const importBlocked = preflight ? !preflight.canProceed : false;

	const handlePrevious = () => {
		setCurrentStep(currentStep - 1);
	};

	return (
		<>
			<ModalHeader currentStep={currentStep} />

			<div className="modal-content-inner">
				<div className="import-options">
					<h3>{sdEdiAdminParams.configureImportTitle}</h3>

					<Row gutter={[30, 20]}>
						<Col xs={24} sm={24} md={12} lg={12} xl={12}>
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
						</Col>

						<Col xs={24} sm={24} md={12} lg={12} xl={12}>
							<div className="import-option new">
								<div className="choose snapshot edi-d-flex edi-align-items-center edi-pos-r">
									<Switch
										checked={snapshot}
										onChange={(checked) =>
											setSnapshot(checked)
										}
									/>
									<h4
										className="edi-d-flex edi-align-items-center"
										style={{ margin: 0 }}
									>
										{sdEdiAdminParams.snapshotTitle ||
											'Create a restore point'}
									</h4>
									<Tooltip
										title={
											sdEdiAdminParams.snapshotHint ||
											'Back up your content before importing so this import can be rolled back. Restoring reverts the site to this moment — anything added after is lost.'
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
								<div className="option-details">
									<p>
										{sdEdiAdminParams.snapshotDetails ||
											'Before importing, a copy of your posts, pages, media (including the uploaded image files, not just their records), categories, tags, menus, comments and site settings (including the theme customizer and widgets) is saved. If you are not happy with the result, one click restores your site to exactly this state — from the result screen or the “Restore point” banner on the importer page. Note: rolling back also removes anything created after this import, and the restore point is replaced the next time you import.'}
									</p>
								</div>
							</div>

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
						</Col>
					</Row>
				</div>

				<div className="step-actions">
					<div className="actions-left">
						<Button type="primary" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnCancel}</span>
						</Button>
					</div>
					<div className="actions-right edi-d-flex edi-align-items-center">
						<Button type="primary" onClick={handlePrevious}>
							<ArrowLeftOutlined />
							<span>{sdEdiAdminParams.btnPrevious}</span>
						</Button>
						<Button
							type="primary"
							onClick={handleImport}
							disabled={importBlocked}
						>
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
