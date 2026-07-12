import React, { useState, useRef } from 'react';
import { Modal, Button, Switch } from 'antd';
import { doAxios } from '../../utils/Api';
import Imports from './steps/Imports';
import Success from './steps/Success';

/* global sdEdiAdminParams */

/**
 * Manual import modal — upload your own WXR (+ optional customizer/widgets),
 * then run the full import pipeline against them. Reuses the existing progress
 * (Imports) and result (Success) steps.
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

	const contentRef = useRef(null);
	const customizerRef = useRef(null);
	const widgetsRef = useRef(null);
	const settingsRef = useRef(null);

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
		setStep(3);
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
		const contentFile = contentRef.current?.files?.[0];

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

		// Uploads the content file one slice per request; the optional small
		// files ride along on the final chunk. The server assembles + validates.
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
				if (customizerRef.current?.files?.[0]) {
					fd.append('customizer', customizerRef.current.files[0]);
				}
				if (widgetsRef.current?.files?.[0]) {
					fd.append('widgets', widgetsRef.current.files[0]);
				}
				if (settingsRef.current?.files?.[0]) {
					fd.append('settings', settingsRef.current.files[0]);
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

	return (
		<Modal
			open={visible}
			onCancel={handleClose}
			footer={null}
			width={720}
			centered
			destroyOnClose
			maskClosable={step !== 3}
			className="edi-manual-modal"
		>
			{step === 1 && (
				<div className="manual-upload">
					<h2>{sdEdiAdminParams.manualTitle || 'Manual Import'}</h2>
					<p className="warn-text">
						{sdEdiAdminParams.manualWarning ||
							'This imports into your current site. Keep the restore point on so you can roll back.'}
					</p>

					<p>
						<label>
							{sdEdiAdminParams.manualContentLabel ||
								'Content (WXR / XML) — required'}
							<br />
							<input type="file" accept=".xml" ref={contentRef} />
						</label>
					</p>
					<p>
						<label>
							{sdEdiAdminParams.manualCustomizerLabel ||
								'Customizer (.dat) — optional'}
							<br />
							<input
								type="file"
								accept=".dat"
								ref={customizerRef}
							/>
						</label>
					</p>
					<p>
						<label>
							{sdEdiAdminParams.manualWidgetsLabel ||
								'Widgets (.wie / .json) — optional'}
							<br />
							<input
								type="file"
								accept=".wie,.json"
								ref={widgetsRef}
							/>
						</label>
					</p>
					<p>
						<label>
							{sdEdiAdminParams.manualSettingsLabel ||
								'Theme settings (.json { option: value }) — optional'}
							<br />
							<input
								type="file"
								accept=".json"
								ref={settingsRef}
							/>
						</label>
					</p>

					<p>
						<Switch checked={snapshot} onChange={setSnapshot} />{' '}
						{sdEdiAdminParams.snapshotTitle ||
							'Create a restore point'}
					</p>
					<p>
						<Switch
							checked={excludeImages}
							onChange={setExcludeImages}
						/>{' '}
						{sdEdiAdminParams.excludeImagesTitle || 'Skip images'}
					</p>

					{error && <p className="warn-text">{error}</p>}

					<div className="step-actions">
						<Button onClick={handleClose}>
							{sdEdiAdminParams.btnCancel || 'Cancel'}
						</Button>
						<Button type="primary" loading={busy} onClick={start}>
							{busy && uploadPct > 0
								? `${sdEdiAdminParams.manualUploading || 'Uploading'} ${uploadPct}%`
								: sdEdiAdminParams.btnStartImport ||
									'Start Import'}
						</Button>
					</div>
				</div>
			)}

			{step === 3 && (
				<Imports
					importStatus=""
					importProgress={progress}
					importPercent={percent}
					showImportProgress
					handleImport={() => {}}
				/>
			)}

			{step === 4 && (
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
		</Modal>
	);
};

export default ManualImportModal;
