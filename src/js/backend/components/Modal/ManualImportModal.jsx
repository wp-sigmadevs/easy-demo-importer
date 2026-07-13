import React, { useState } from 'react';
import {
	Modal,
	Button,
	Switch,
	Steps,
	Row,
	Col,
	Upload,
	Tooltip,
	Segmented,
} from 'antd';
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
 * Manual import modal — upload your own export and run the full import pipeline
 * against it. Two modes feed the same server machinery:
 *   - Separate: content .xml (required) + optional customizer .dat / widgets
 *     .wie / settings (.json or a .zip of per-option JSONs) / images .zip.
 *   - Bundle: a single .zip containing all of the above.
 * Every file is uploaded in chunks (so large image/bundle zips beat PHP upload
 * limits), then a finalize call unpacks + routes them server-side.
 *
 * Shares the wizard modal's chrome and reuses the Imports (progress) and Success
 * (result) steps. Step values align with doAxios (which drives the result to
 * step 5): Upload = 1, Import = 2, End = 5; the three dots map from those.
 *
 * @param {Object}   props         - Props.
 * @param {boolean}  props.visible - Whether the modal is open.
 * @param {Function} props.onClose - Close handler.
 * @return {JSX.Element} The modal.
 */
const ManualImportModal = ({ visible, onClose }) => {
	const [mode, setMode] = useState('separate');
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
	const [imagesFile, setImagesFile] = useState(null);
	const [bundleFile, setBundleFile] = useState(null);

	const reset = () => {
		setMode('separate');
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
		setImagesFile(null);
		setBundleFile(null);
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

	// Runs the import pipeline once the upload has assembled + finalized. When the
	// server extracted bundled media, image downloading is skipped (the files are
	// already in uploads).
	const proceed = (key, sid, hasImages) => {
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
			excludeImages: hasImages || excludeImages ? 'true' : 'false',
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

	// Uploads one file to its named target, chunk by chunk. Resolves when the
	// server has assembled the whole file; rejects with a message on failure.
	const uploadFile = (file, target, uploadId, onProgress) =>
		new Promise((resolve, reject) => {
			const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

			const send = (i) => {
				const blob = file.slice(i * CHUNK_SIZE, (i + 1) * CHUNK_SIZE);
				const fd = new FormData();
				fd.append('action', 'sd_edi_manual_upload');
				fd.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
				fd.append('uploadId', uploadId);
				fd.append('target', target);
				fd.append('chunkIndex', i);
				fd.append('totalChunks', total);
				fd.append('chunk', blob, file.name);

				fetch(sdEdiAdminParams.ajaxUrl, {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
				})
					.then((r) => r.json())
					.then((res) => {
						if (!res?.success) {
							reject(res?.data?.message || 'Upload failed.');
							return;
						}
						onProgress((i + 1) / total);
						if (res.data.done) {
							resolve();
						} else {
							send(i + 1);
						}
					})
					.catch(() => reject('Upload failed.'));
			};

			send(0);
		});

	// Asks the server to unpack + route the staged files and start the session.
	const finalizeUpload = (uploadId) => {
		const fd = new FormData();
		fd.append('action', 'sd_edi_manual_upload');
		fd.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
		fd.append('uploadId', uploadId);
		fd.append('finalize', '1');

		return fetch(sdEdiAdminParams.ajaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
		}).then((r) => r.json());
	};

	// Builds the [file, target] list for the active mode.
	const filesToUpload = () => {
		if (mode === 'bundle') {
			return bundleFile ? [[bundleFile, 'bundle']] : [];
		}

		const list = [[contentFile, 'content']];

		if (customizerFile) {
			list.push([customizerFile, 'customizer']);
		}
		if (widgetsFile) {
			list.push([widgetsFile, 'widgets']);
		}
		if (settingsFile) {
			const isZip = /\.zip$/i.test(settingsFile.name);
			list.push([settingsFile, isZip ? 'settingsZip' : 'settings']);
		}
		if (imagesFile) {
			list.push([imagesFile, 'images']);
		}

		return list;
	};

	const start = async () => {
		if (mode === 'bundle' && !bundleFile) {
			setError(
				sdEdiAdminParams.manualNoBundle ||
					'Please choose a bundle .zip file.'
			);
			return;
		}
		if (mode === 'separate' && !contentFile) {
			setError(
				sdEdiAdminParams.manualNoContent ||
					'Please choose a content (WXR/XML) file.'
			);
			return;
		}

		const files = filesToUpload();
		const uploadId = (
			Date.now().toString(16) + Math.random().toString(16).slice(2)
		)
			.replace(/[^a-f0-9]/g, '')
			.slice(0, 20);

		setError('');
		setBusy(true);
		setUploadPct(0);

		try {
			for (let n = 0; n < files.length; n++) {
				const [file, target] = files[n];
				// eslint-disable-next-line no-await-in-loop
				await uploadFile(file, target, uploadId, (p) => {
					setUploadPct(Math.round(((n + p) / files.length) * 100));
				});
			}

			const res = await finalizeUpload(uploadId);

			if (!res?.success) {
				setBusy(false);
				setError(res?.data?.message || 'Import could not be prepared.');
				return;
			}

			proceed(
				res.data.manualKey,
				res.data.sessionId,
				!!res.data.hasImages
			);
		} catch (e) {
			setBusy(false);
			setError(typeof e === 'string' ? e : 'Upload failed.');
		}
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

	const startDisabled = mode === 'bundle' ? !bundleFile : !contentFile;

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
									<div className="manual-mode-switch">
										<Segmented
											value={mode}
											onChange={(v) => {
												setMode(v);
												setError('');
											}}
											options={[
												{
													label:
														sdEdiAdminParams.manualModeSeparate ||
														'Separate files',
													value: 'separate',
												},
												{
													label:
														sdEdiAdminParams.manualModeBundle ||
														'Single bundle (.zip)',
													value: 'bundle',
												},
											]}
										/>
									</div>

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

												{mode === 'bundle'
													? filePicker({
															file: bundleFile,
															setFile:
																setBundleFile,
															accept: '.zip',
															label:
																sdEdiAdminParams.manualBundleLabel ||
																'Bundle (.zip: content, customizer, widgets, settings, images) — required',
														})
													: [
															filePicker({
																file: contentFile,
																setFile:
																	setContentFile,
																accept: '.xml',
																label:
																	sdEdiAdminParams.manualContentLabel ||
																	'Content (WXR / XML) — required',
															}),
															filePicker({
																file: customizerFile,
																setFile:
																	setCustomizerFile,
																accept: '.dat',
																label:
																	sdEdiAdminParams.manualCustomizerLabel ||
																	'Customizer (.dat) — optional',
															}),
															filePicker({
																file: widgetsFile,
																setFile:
																	setWidgetsFile,
																accept: '.wie,.json',
																label:
																	sdEdiAdminParams.manualWidgetsLabel ||
																	'Widgets (.wie / .json) — optional',
															}),
															filePicker({
																file: settingsFile,
																setFile:
																	setSettingsFile,
																accept: '.json,.zip',
																label:
																	sdEdiAdminParams.manualSettingsLabel ||
																	'Settings — single .json, or a .zip of per-option JSONs — optional',
															}),
															filePicker({
																file: imagesFile,
																setFile:
																	setImagesFile,
																accept: '.zip',
																label:
																	sdEdiAdminParams.manualImagesLabel ||
																	'Images (.zip, mirrors the uploads folder) — optional',
															}),
														].map((picker, i) => (
															<React.Fragment
																key={i}
															>
																{picker}
															</React.Fragment>
														))}
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
																sdEdiAdminParams.manualImportImagesHint ||
																'Downloads images referenced by the export. Automatically skipped when you provide an images .zip.'
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
											disabled={startDisabled}
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
