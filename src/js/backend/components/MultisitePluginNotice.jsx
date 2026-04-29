import React, { useState } from 'react';

/* global sdEdiAdminParams */

/**
 * Returns the names of plugins that have status 'install' (missing on disk).
 */
const collectMissing = (plugins) =>
	plugins.filter((p) => p && p.status === 'install').map((p) => ({ name: p.name, key: p.key }));

/**
 * Multisite-aware notice rendered above the plugin list in the Setup step.
 *
 * Renders:
 *  - nothing on single-site
 *  - nothing when no plugins are missing
 *  - "ask Network Admin" block when Super Admin can't install
 *  - "Install on network" CTA when current user is Super Admin
 */
const MultisitePluginNotice = ({ plugins }) => {
	const params = (typeof sdEdiAdminParams !== 'undefined') ? sdEdiAdminParams : {};
	const [installing, setInstalling] = useState(false);
	const [progress, setProgress] = useState('');
	const [errorMsg, setErrorMsg] = useState('');

	if (!params.isMultisite) {
		return null;
	}

	const missing = collectMissing(plugins || []);
	if (missing.length === 0) {
		return null;
	}

	if (!params.canInstallPlugins) {
		const subject = encodeURIComponent(
			params.networkContactSubject || 'Required plugins missing'
		);
		const bodyText =
			(params.networkContactBody || '') +
			missing.map((p) => `- ${p.name}`).join('\n');
		const body = encodeURIComponent(bodyText);

		return (
			<div className="sd-edi-multisite-notice sd-edi-multisite-notice--blocked">
				<p>
					<strong>Network Admin must install the following plugins network-wide:</strong>
				</p>
				<ul>
					{missing.map((p) => (
						<li key={p.key}>{p.name}</li>
					))}
				</ul>
				<div className="sd-edi-multisite-notice__actions">
					<a
						className="ant-btn ant-btn-primary"
						href={`mailto:?subject=${subject}&body=${body}`}
					>
						Notify Network Admin
					</a>
					<button
						className="ant-btn"
						type="button"
						onClick={() => window.location.reload()}
					>
						Refresh
					</button>
				</div>
			</div>
		);
	}

	const installAll = async () => {
		if (!params.restApiUrl || !params.restNonce) {
			setErrorMsg('REST API not available.');
			return;
		}
		setInstalling(true);
		setErrorMsg('');
		const networkBase = params.restApiUrl.replace(/\/$/, '') + '/sd-edi/v1';
		const failures = [];
		for (let i = 0; i < missing.length; i++) {
			const item = missing[i];
			setProgress(`Installing ${item.name} (${i + 1}/${missing.length})…`);
			try {
				const resp = await fetch(networkBase + '/network/install-plugin', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': params.restNonce,
					},
					body: JSON.stringify({ slug: item.key }),
				});
				const data = await resp.json();
				if (!resp.ok || !data.ok) {
					failures.push(`${item.name}: ${data.message || resp.statusText}`);
				}
			} catch (e) {
				failures.push(`${item.name}: ${String(e)}`);
			}
		}
		setInstalling(false);
		setProgress('');
		if (failures.length > 0) {
			setErrorMsg(failures.join(' | '));
		} else {
			window.location.reload();
		}
	};

	return (
		<div className="sd-edi-multisite-notice sd-edi-multisite-notice--super">
			<p>
				<strong>Required plugins are missing on this network.</strong> As Super Admin you can install them network-wide:
			</p>
			<ul>
				{missing.map((p) => (
					<li key={p.key}>{p.name}</li>
				))}
			</ul>
			<div className="sd-edi-multisite-notice__actions">
				<button
					className="ant-btn ant-btn-primary"
					type="button"
					onClick={installAll}
					disabled={installing}
				>
					{installing ? progress || 'Installing…' : 'Install all on network'}
				</button>
			</div>
			{errorMsg && (
				<p className="sd-edi-multisite-notice__error">{errorMsg}</p>
			)}
		</div>
	);
};

export default MultisitePluginNotice;
