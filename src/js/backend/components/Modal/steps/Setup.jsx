import ModalHeader from '../ModalHeader';
import React from 'react';
import { Button, Col, Row, Switch } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {
	ArrowLeftOutlined,
	CloseOutlined,
	DownloadOutlined,
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

					<Row gutter={[30, 24]}>
						<Col xs={24} sm={24} md={12} lg={12} xl={12}>
							<div className="configure-group is-plain">
								<h5 className="configure-group-label">
									{sdEdiAdminParams.configurePerformanceLabel ||
										'Performance'}
								</h5>

								<div className="import-option">
									<div className="choose edi-d-flex edi-align-items-center">
										<Switch
											checked={!excludeImages}
											onChange={(checked) =>
												setExcludeImages(!checked)
											}
										/>
										<h4>
											{sdEdiAdminParams.importImagesTitle ||
												'Import Demo Images'}
										</h4>
									</div>
									<div className="option-details">
										<p>
											{sdEdiAdminParams.importImagesHint ||
												'Downloads the demo images. Turn off for a faster import, or if the import fails repeatedly.'}
										</p>
									</div>
								</div>

								{!excludeImages && (
									<div className="import-option">
										<div className="choose edi-d-flex edi-align-items-center">
											<Switch
												checked={!skipImageRegeneration}
												onChange={(checked) =>
													setSkipImageRegeneration(
														!checked
													)
												}
											/>
											<h4>
												{sdEdiAdminParams.regenerateImagesTitle ||
													'Regenerate Images'}
											</h4>
										</div>
										<div className="option-details">
											<p>
												{sdEdiAdminParams.regenerateImagesHint ||
													'Regenerates thumbnail sizes during import. Turn off for a faster import; you can regenerate later from Tools.'}
											</p>
										</div>
									</div>
								)}
							</div>
						</Col>

						<Col xs={24} sm={24} md={12} lg={12} xl={12}>
							<div className="configure-group is-plain">
								<h5 className="configure-group-label">
									{sdEdiAdminParams.configureSafetyLabel ||
										'Safety'}
								</h5>

								<div className="safety-card">
									<div className="import-option">
										<div className="choose edi-d-flex edi-align-items-center">
											<Switch
												checked={snapshot}
												onChange={(checked) =>
													setSnapshot(checked)
												}
											/>
											<h4>
												{sdEdiAdminParams.snapshotTitle ||
													'Create a restore point'}
											</h4>
										</div>
										<div className="option-details">
											<p>
												{sdEdiAdminParams.snapshotDetails ||
													'Saves a full backup — content, media files, and settings — before importing. One click restores this exact state from the result screen or the restore-point banner. Rolling back also removes anything created after the import.'}
											</p>
										</div>
									</div>

									<div className="import-option last">
										<div className="choose edi-d-flex edi-align-items-center">
											<Switch
												checked={reset}
												onChange={(checked) =>
													setReset(checked)
												}
											/>
											<h4>
												{
													sdEdiAdminParams.resetDatabaseTitle
												}
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
												{
													sdEdiAdminParams.resetDatabaseHint
												}
											</p>
										</div>
									</div>
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
