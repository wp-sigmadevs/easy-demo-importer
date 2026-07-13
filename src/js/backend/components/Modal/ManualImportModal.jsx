import React, { useState } from 'react';
import { Modal, Button, Switch, Steps, Row, Col, Upload, Tooltip } from 'antd';
import {
	UploadOutlined,
	CloseOutlined,
	DownloadOutlined,
	QuestionCircleTwoTone,
} from '@ant-design/icons';
import { doAxios } from '../../utils/Api';
import Imports from './steps/Imports';
import Success from './steps/Success';

/* global sdEdiAdminParams */

/**
 * Manual import modal — upload your own WXR (+ optional customizer / widgets /
 * settings) and run the full import pipeline against them. Shares the wizard
 * modal's chrome (step indicator, option groups, action bar) so it matches the
 * demo-import experience, and reuses the Imports (progress) and Success (result)
 * steps.
 *
 * Step numbers align with the shared doAxios helper: it drives the result screen
 * to step 5, so Upload = 1, Import (progress) = 2, End (result) = 5. The three
 * dots are mapped from those values.
 *
 * @param {Object}   props         - Props.
 * @param {boolean}  props.visible - Whether the modal is open.
 * @param {Function} props.onClose - Close handler.
 * @return {JSX.Element} The modal.
 */
const ManualImportModal = ({ visible, onClose }) => {
	const [step, setStep] = useState(1);
	const [snapshot, setSnapshot] = useState(true);
	const [excludeImages, setExcludeImages] = useState(false);
	const [busy, setBusy] = useState(false);
	const [uploadPct, setUploadPct] = useState(0);
	const [error, setError] = useState('');
	const [progress, setProgress] = useState([]);
	const [percent, setPercent] = useState(null);
	const [complete, setComplete] = useState(false);
	const [message, setMessage] = useState('');
	const [hint, setHint] = useState('');
	const [sessionId, setSessionId] = useState('');
	const [manualKey, setManualKey] = useState('');

	const [contentFile, setContentFile] = useState(null);
	const [customizerFile, setCustomizerFile] = useState(null);
	const [widgetsFile, setWidgetsFile] = useState(null);
	const [settingsFile, setSettingsFile] = useState(null);

	const reset = () => {
		setStep(1);
		setBusy(false);
		setUploadPct(0);
		setError('');
		setProgress([]);
		setPercent(null);
		setComplete(false);
		setMessage('');
		setHint('');
		setSessionId('');
		setManualKey('');
		setContentFile(null);
		setCustomizerFile(null);
		setWidgetsFile(null);
		setSettingsFile(null);
	};

	const handleClose = () => {
		reset();
		onClose();
	};

	const handleImportResponse = (response) => {
		if (response.data.sessionId) {
			setSessionId(response.data.sessionId);
		}
		if (!response.data.error && !response.data.nextPhase) {
			setMessage(response.data.completedMessage);
			setComplete(true);
		}
	};

	// Runs the import pipeline once the upload has assembled server-side.
	const proceed = (key, sid) => {
		setSessionId(sid);
		setManualKey(key);
		setStep(2);
		setProgress([
			{ message: sdEdiAdminParams.prepareImporting || 'Preparing…' },
		]);

		const request = {
			demo: '__manual__',
			manual: 'true',
			manualKey: key,
			reset: false,
			snapshot: snapshot ? 'true' : 'false',
			excludeImages: excludeImages ? 'true' : 'false',
			skipImageRegeneration: 'false',
			sessionId: sid,
			nextPhase: 'sd_edi_import_xml',
		};

		doAxios(
			request,
			setProgress,
			setStep,
			handleImportResponse,
			setMessage,
			setHint,
			() => {},
			setPercent
		);
	};

	const CHUNK_SIZE = 4 * 1024 * 1024;

	const start = () => {
		if (!contentFile) {
			setError(
				sdEdiAdminParams.manualNoContent ||
					'Please choose a content (WXR/XML) file.'
			);
			return;
		}

		setError('');
		setBusy(true);
		setUploadPct(0);

		const total = Math.max(1, Math.ceil(contentFile.size / CHUNK_SIZE));
		const uploadId = (
			Date.now().toString(16) + Math.random().toString(16).slice(2)
		)
			.replace(/[^a-f0-9]/g, '')
			.slice(0, 20);

		// Uploads the content file one slice per request; the optional small files
		// ride along on the final chunk. The server assembles + validates.
		const sendChunk = (i) => {
			const startByte = i * CHUNK_SIZE;
			const blob = contentFile.slice(startByte, startByte + CHUNK_SIZE);

			const fd = new FormData();
			fd.append('action', 'sd_edi_manual_upload');
			fd.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
			fd.append('uploadId', uploadId);
			fd.append('chunkIndex', i);
			fd.append('totalChunks', total);
			fd.append('chunk', blob, 'content.xml');

			if (i === total - 1) {
				if (customizerFile) {
					fd.append('customizer', customizerFile);
				}
				if (widgetsFile) {
					fd.append('widgets', widgetsFile);
				}
				if (settingsFile) {
					fd.append('settings', settingsFile);
				}
			}

			fetch(sdEdiAdminParams.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
			})
				.then((r) => r.json())
				.then((res) => {
					if (!res?.success) {
						setBusy(false);
						setError(res?.data?.message || 'Upload failed.');
						return;
					}

					if (res.data.done) {
						proceed(res.data.manualKey, res.data.sessionId);
					} else {
						setUploadPct(Math.round(((i + 1) / total) * 100));
						sendChunk(i + 1);
					}
				})
				.catch(() => {
					setBusy(false);
					setError('Upload failed.');
				});
		};

		sendChunk(0);
	};

	// A single controlled file picker rendered as an antd Upload. Prevents the
	// auto-upload (beforeUpload returns false) and just captures the File.
	const filePicker = ({ file, setFile, accept, label }) => (
		<div className="manual-file">
			<Upload
				accept={accept}
				maxCount={1}
				fileList={file ? [file] : []}
				beforeUpload={(f) => {
					setFile(f);
					setError('');
					return false;
				}}
				onRemove={() => setFile(null)}
			>
				<Button icon={<UploadOutlined />}>
					{sdEdiAdminParams.manualChooseFile || 'Choose File'}
				</Button>
			</Upload>
			<span className="manual-file-label">{label}</span>
		</div>
	);

	const STEP_ITEMS = [
		{ title: sdEdiAdminParams.manualStepUpload || 'Upload' },
		{ title: sdEdiAdminParams.manualStepImport || 'Import' },
		{ title: sdEdiAdminParams.manualStepEnd || 'End' },
	];

	// Map the doAxios-driven step values (1 / 2 / 5) onto the three dots.
	const DOT_BY_STEP = { 1: 0, 2: 1, 5: 2 };
	const dotCurrent = DOT_BY_STEP[step] ?? 2;

	return (
		<Modal
			open={visible}
			closable={false}
			footer={null}
			width={900}
			centered
			destroyOnClose
			maskClosable={step === 1}
			onCancel={step === 1 ? handleClose : undefined}
			className="edi-manual-modal"
		>
			<Row>
				<Col span={24}>
					<div className="modal-steps">
						<h2>
							{sdEdiAdminParams.manualModalHeaderPrefix ||
								'Manual Import'}
						</h2>
						<Steps
							progressDot
							current={dotCurrent}
							items={STEP_ITEMS}
						/>
					</div>

					<div
						className={`modal-content step ${
							step === 1 ? 'fade-in' : 'fade-out'
						}`}
					>
						{step === 1 && (
							<div className="modal-content-inner">
								<div className="import-options">
									<h3>
										{sdEdiAdminParams.manualUploadTitle ||
											'Upload Your Export Files'}
									</h3>

									<Row gutter={[30, 24]}>
										<Col
											xs={24}
											sm={24}
											md={12}
											lg={12}
											xl={12}
										>
											<div className="configure-group is-plain">
												<h5 className="configure-group-label">
													{sdEdiAdminParams.manualFilesLabel ||
														'Files'}
												</h5>

												{filePicker({
													file: contentFile,
													setFile: setContentFile,
													accept: '.xml',
													label:
														sdEdiAdminParams.manualContentLabel ||
														'Content (WXR / XML) — required',
												})}
												{filePicker({
													file: customizerFile,
													setFile: setCustomizerFile,
													accept: '.dat',
													label:
														sdEdiAdminParams.manualCustomizerLabel ||
														'Customizer (.dat) — optional',
												})}
												{filePicker({
													file: widgetsFile,
													setFile: setWidgetsFile,
													accept: '.wie,.json',
													label:
														sdEdiAdminParams.manualWidgetsLabel ||
														'Widgets (.wie / .json) — optional',
												})}
												{filePicker({
													file: settingsFile,
													setFile: setSettingsFile,
													accept: '.json',
													label:
														sdEdiAdminParams.manualSettingsLabel ||
														'Theme settings (.json) — optional',
												})}
											</div>
										</Col>

										<Col
											xs={24}
											sm={24}
											md={12}
											lg={12}
											xl={12}
										>
											<div className="configure-group is-card">
												<h5 className="configure-group-label">
													{sdEdiAdminParams.configureSafetyLabel ||
														'Safety'}
												</h5>

												<div className="import-option">
													<div className="choose edi-d-flex edi-align-items-center">
														<Switch
															checked={snapshot}
															onChange={
																setSnapshot
															}
														/>
														<h4>
															{sdEdiAdminParams.snapshotTitle ||
																'Create a restore point'}
														</h4>
													</div>
													<div className="option-details warn-text">
														<p>
															{sdEdiAdminParams.manualWarning ||
																'This imports into your current site. Keep the restore point on so you can roll back.'}
														</p>
													</div>
												</div>

												<div className="import-option last">
													<div className="choose edi-d-flex edi-align-items-center">
														<Switch
															checked={
																!excludeImages
															}
															onChange={(
																checked
															) =>
																setExcludeImages(
																	!checked
																)
															}
														/>
														<h4>
															{sdEdiAdminParams.importImagesTitle ||
																'Import Demo Images'}
														</h4>
														<Tooltip
															title={
																sdEdiAdminParams.importImagesHint
															}
														>
															<span className="manual-help">
																<QuestionCircleTwoTone />
															</span>
														</Tooltip>
													</div>
												</div>
											</div>
										</Col>
									</Row>

									{error && (
										<p
											className="warn-text"
											style={{ marginTop: 12 }}
										>
											{error}
										</p>
									)}
								</div>

								<div className="step-actions">
									<div className="actions-left">
										<Button
											type="primary"
											onClick={handleClose}
										>
											<CloseOutlined />
											<span>
												{sdEdiAdminParams.btnCancel ||
													'Cancel'}
											</span>
										</Button>
									</div>
									<div className="actions-right edi-d-flex edi-align-items-center">
										<Button
											type="primary"
											loading={busy}
											disabled={!contentFile}
											onClick={start}
										>
											<span>
												{busy && uploadPct > 0
													? `${sdEdiAdminParams.manualUploading || 'Uploading'} ${uploadPct}%`
													: sdEdiAdminParams.btnStartImport ||
														'Start Import'}
											</span>
											<DownloadOutlined />
										</Button>
									</div>
								</div>
							</div>
						)}
					</div>

					<div
						className={`modal-content step import-step ${
							step === 2 ? 'fade-in' : 'fade-out'
						}`}
					>
						{step === 2 && (
							<Imports
								importStatus=""
								importProgress={progress}
								importPercent={percent}
								showImportProgress
								handleImport={() => {}}
							/>
						)}
					</div>

					<div
						className={`modal-content step ${
							step === 5 ? 'fade-in' : 'fade-out'
						}`}
					>
						{step === 5 && (
							<Success
								importComplete={complete}
								handleReset={handleClose}
								handleResume={() => {}}
								handleStartOver={handleClose}
								canResume={false}
								message={message}
								hint={hint}
								demo="__manual__"
								sessionId={sessionId}
								manual="true"
								manualKey={manualKey}
							/>
						)}
					</div>
				</Col>
			</Row>
		</Modal>
	);
};

export default ManualImportModal;
