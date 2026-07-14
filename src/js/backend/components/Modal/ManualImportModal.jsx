import React, { useState } from 'react';
import { Modal, Button, Switch, Steps, Row, Col, Upload, Tabs } from 'antd';
import {
	CloseOutlined,
	DeleteOutlined,
	DownloadOutlined,
	CheckCircleFilled,
	FileTextOutlined,
	FileZipOutlined,
	BgColorsOutlined,
	AppstoreOutlined,
	SettingOutlined,
	PictureOutlined,
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

	const formatSize = (bytes) =>
		bytes >= 1024 * 1024
			? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
			: `${Math.max(1, Math.round(bytes / 1024))} KB`;

	// A single controlled file slot rendered as an antd Dragger, so each slot
	// accepts both drag-and-drop and click-to-browse. Auto-upload is prevented
	// (beforeUpload returns false) — the File is just captured into state.
	// `wide` renders the large hero variant (content slot / bundle mode).
	const fileSlot = ({
		file,
		setFile,
		accept,
		icon,
		title,
		subtitle,
		required = false,
		wide = false,
	}) => (
		<Upload.Dragger
			className={`manual-slot${wide ? ' is-wide' : ''}${
				file ? ' is-filled' : ''
			}`}
			accept={accept}
			maxCount={1}
			fileList={[]}
			showUploadList={false}
			beforeUpload={(f) => {
				setFile(f);
				setError('');
				return false;
			}}
		>
			<div className="manual-slot-body">
				<span className="manual-slot-icon">
					{file ? <CheckCircleFilled /> : icon}
				</span>
				<span className="manual-slot-meta">
					<span className="manual-slot-title" title={file?.name}>
						{file ? file.name : title}
					</span>
					<span className="manual-slot-hint">
						{file ? formatSize(file.size) : subtitle}
					</span>
				</span>
				{file ? (
					<Button
						className="manual-slot-remove"
						type="text"
						size="small"
						icon={<DeleteOutlined />}
						onClick={(e) => {
							e.stopPropagation();
							setFile(null);
						}}
					/>
				) : (
					<span
						className={`manual-slot-tag${
							required ? ' is-required' : ''
						}`}
					>
						{required
							? sdEdiAdminParams.manualRequired || 'Required'
							: sdEdiAdminParams.manualOptional || 'Optional'}
					</span>
				)}
			</div>
			{wide && !file && (
				<p className="manual-slot-drop-hint">
					{sdEdiAdminParams.manualDropHint ||
						'Drop a file or click to browse'}
				</p>
			)}
		</Upload.Dragger>
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
										<Tabs
											className="edi-manual-switch"
											activeKey={mode}
											onChange={(v) => {
												setMode(v);
												setError('');
											}}
											items={[
												{
													key: 'separate',
													label:
														sdEdiAdminParams.manualModeSeparate ||
														'Separate files',
												},
												{
													key: 'bundle',
													label:
														sdEdiAdminParams.manualModeBundle ||
														'Single bundle (.zip)',
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

												{mode === 'bundle' ? (
													fileSlot({
														file: bundleFile,
														setFile: setBundleFile,
														accept: '.zip',
														icon: (
															<FileZipOutlined />
														),
														title:
															sdEdiAdminParams.manualBundleDropTitle ||
															'Drop your bundle .zip here',
														subtitle:
															sdEdiAdminParams.manualBundleDropHint ||
															'One .zip containing content, customizer, widgets, settings and images',
														required: true,
														wide: true,
													})
												) : (
													<>
														{fileSlot({
															file: contentFile,
															setFile:
																setContentFile,
															accept: '.xml',
															icon: (
																<FileTextOutlined />
															),
															title:
																sdEdiAdminParams.manualSlotContentTitle ||
																'Content',
															subtitle:
																sdEdiAdminParams.manualSlotContentHint ||
																'WXR / XML export',
															required: true,
															wide: true,
														})}
														<div className="manual-slot-grid">
															{fileSlot({
																file: customizerFile,
																setFile:
																	setCustomizerFile,
																accept: '.dat',
																icon: (
																	<BgColorsOutlined />
																),
																title:
																	sdEdiAdminParams.manualSlotCustomizerTitle ||
																	'Customizer',
																subtitle:
																	sdEdiAdminParams.manualSlotCustomizerHint ||
																	'.dat file',
															})}
															{fileSlot({
																file: widgetsFile,
																setFile:
																	setWidgetsFile,
																accept: '.wie,.json',
																icon: (
																	<AppstoreOutlined />
																),
																title:
																	sdEdiAdminParams.manualSlotWidgetsTitle ||
																	'Widgets',
																subtitle:
																	sdEdiAdminParams.manualSlotWidgetsHint ||
																	'.wie or .json',
															})}
															{fileSlot({
																file: settingsFile,
																setFile:
																	setSettingsFile,
																accept: '.json,.zip',
																icon: (
																	<SettingOutlined />
																),
																title:
																	sdEdiAdminParams.manualSlotSettingsTitle ||
																	'Settings',
																subtitle:
																	sdEdiAdminParams.manualSlotSettingsHint ||
																	'.json, or .zip of JSONs',
															})}
															{fileSlot({
																file: imagesFile,
																setFile:
																	setImagesFile,
																accept: '.zip',
																icon: (
																	<PictureOutlined />
																),
																title:
																	sdEdiAdminParams.manualSlotImagesTitle ||
																	'Images',
																subtitle:
																	sdEdiAdminParams.manualSlotImagesHint ||
																	'.zip of the uploads folder',
															})}
														</div>
													</>
												)}
											</div>
										</Col>

										<Col
											xs={24}
											sm={24}
											md={12}
											lg={12}
											xl={12}
										>
											<div className="configure-group is-plain">
												<h5 className="configure-group-label">
													{sdEdiAdminParams.configureSafetyLabel ||
														'Safety'}
												</h5>

												<div className="safety-card">
													<div className="import-option">
														<div className="choose edi-d-flex edi-align-items-center">
															<Switch
																checked={
																	snapshot
																}
																onChange={
																	setSnapshot
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
														</div>
														<div className="option-details">
															<p>
																{sdEdiAdminParams.manualImportImagesHint ||
																	'Downloads images referenced by the export. Automatically skipped when you provide an images .zip.'}
															</p>
														</div>
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
