import React, { useEffect, useState } from 'react';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

export default function ConfigEditor() {
	const [enabled, setEnabled] = useState(false);
	const [json, setJson] = useState('{}');
	const [saving, setSaving] = useState(false);
	const [msg, setMsg] = useState('');

	useEffect(() => {
		fetch(params.restUrl + 'network/config', {
			headers: { 'X-WP-Nonce': params.restNonce },
		})
			.then((r) => r.json())
			.then((d) => {
				setEnabled(!!d.enabled);
				setJson(JSON.stringify(d.config || {}, null, 2));
			});
	}, []);

	function save() {
		let parsed;
		try {
			parsed = JSON.parse(json);
		} catch (e) {
			setMsg(String(e));
			return;
		}
		setSaving(true);
		setMsg('');
		fetch(params.restUrl + 'network/config', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': params.restNonce,
			},
			body: JSON.stringify({ enabled, config: parsed }),
		})
			.then((r) => r.json())
			.then((d) => {
				if (d.ok) setMsg('Saved.');
				else setMsg(d.message || i18n.configInvalid);
			})
			.finally(() => setSaving(false));
	}

	return (
		<div className="sd-edi-network__config">
			<label className="sd-edi-toggle">
				<input
					type="checkbox"
					checked={enabled}
					onChange={(e) => setEnabled(e.target.checked)}
				/>
				{i18n.overrideEnabled}
			</label>
			<textarea
				rows={24}
				value={json}
				onChange={(e) => setJson(e.target.value)}
				className="sd-edi-network__json"
				spellCheck="false"
			/>
			<div className="sd-edi-network__actions">
				<button onClick={save} disabled={saving} className="button button-primary">
					{i18n.save}
				</button>
				{msg && <span className="sd-edi-network__msg">{msg}</span>}
			</div>
		</div>
	);
}
