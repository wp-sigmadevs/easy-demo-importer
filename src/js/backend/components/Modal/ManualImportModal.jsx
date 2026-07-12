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

	const reset = () => {
		setStep(1);
		setBusy(false);
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

		const fd = new FormData();
		fd.append('action', 'sd_edi_manual_upload');
		fd.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
		fd.append('content', contentFile);

		if (customizerRef.current?.files?.[0]) {
			fd.append('customizer', customizerRef.current.files[0]);
		}
		if (widgetsRef.current?.files?.[0]) {
			fd.append('widgets', widgetsRef.current.files[0]);
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

				const key = res.data.manualKey;
				const sid = res.data.sessionId;
				setSessionId(sid);
				setManualKey(key);
				setStep(3);
				setProgress([
					{
						message:
							sdEdiAdminParams.prepareImporting || 'Preparing…',
					},
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
			})
			.catch(() => {
				setBusy(false);
				setError('Upload failed.');
			});
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
							{sdEdiAdminParams.btnStartImport || 'Start Import'}
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
