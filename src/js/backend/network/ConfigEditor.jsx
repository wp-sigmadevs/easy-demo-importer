import React, { useEffect, useState } from 'react';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

export default function ConfigEditor() {
	const [enabled, setEnabled] = useState(false);
	const [json, setJson] = useState('{}');
	const [saving, setSaving] = useState(false);
	const [msg, setMsg] = useState('');
	const [loading, setLoading] = useState(true);
	const [loadError, setLoadError] = useState('');

	useEffect(() => {
		fetch(params.restUrl + 'network/config', {
			headers: { 'X-WP-Nonce': params.restNonce },
		})
			.then(async (r) => {
				const data = await r.json().catch(() => ({}));
				if (!r.ok || data.code) {
					throw new Error(
						(data && data.message) ||
							`HTTP ${r.status} ${r.statusText || ''}`.trim()
					);
				}
				return data;
			})
			.then((d) => {
				setEnabled(!!d.enabled);
				setJson(JSON.stringify(d.config || {}, null, 2));
			})
			.catch((e) => {
				setLoadError(String(e.message || e));
			})
			.finally(() => setLoading(false));
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
			.then(async (r) => {
				const data = await r.json().catch(() => ({}));
				if (!r.ok || data.code) {
					setMsg(
						(data && data.message) ||
							i18n.configInvalid ||
							`HTTP ${r.status}`
					);
					return;
				}
				if (data.ok) setMsg('Saved.');
				else setMsg(data.message || i18n.configInvalid);
			})
			.catch((e) => setMsg(String(e.message || e)))
			.finally(() => setSaving(false));
	}

	if (loading) {
		return <p>{i18n.loading || 'Loading…'}</p>;
	}

	if (loadError) {
		return (
			<div className="sd-edi-network__error">
				<p>
					<strong>{i18n.loadError || 'Could not load network config:'}</strong>
				</p>
				<p>
					<code>{loadError}</code>
				</p>
				<button
					className="button"
					onClick={() => window.location.reload()}
				>
					{i18n.retry || 'Retry'}
				</button>
			</div>
		);
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
