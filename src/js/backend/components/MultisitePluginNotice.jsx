import React, { useState } from 'react';

/* global sdEdiAdminParams */

/**
 * Returns the names of plugins that have status 'install' (missing on disk).
 */
// Strip CR/LF from plugin names before they end up in mailto headers.
const sanitizeName = (n) => String(n || '').replace(/[\r\n]/g, ' ');

const collectMissing = (plugins) =>
	plugins
		.filter((p) => p && p.status === 'install')
		.map((p) => ({ name: sanitizeName(p.name), key: p.key }));

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
					<strong>{params.i18nNetworkBlockTitle}</strong>
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
						{params.i18nNotifyNetworkAdmin}
					</a>
					<button
						className="ant-btn"
						type="button"
						onClick={() => window.location.reload()}
					>
						{params.i18nRefresh}
					</button>
				</div>
			</div>
		);
	}

	const installAll = async () => {
		if (!params.restApiUrl || !params.restNonce) {
			setErrorMsg(params.i18nRestUnavailable || 'REST API not available.');
			return;
		}
		setInstalling(true);
		setErrorMsg('');
		const networkBase = params.restApiUrl.replace(/\/$/, '') + '/sd-edi/v1';
		const failures = [];
		const tpl = params.i18nInstallingProgress || 'Installing %1$s (%2$d/%3$d)…';
		for (let i = 0; i < missing.length; i++) {
			const item = missing[i];
			setProgress(
				tpl
					.replace('%1$s', item.name)
					.replace('%2$d', String(i + 1))
					.replace('%3$d', String(missing.length))
			);
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
				<strong>{params.i18nNetworkSuperTitle}</strong>
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
					{installing
						? progress || params.i18nInstalling || 'Installing…'
						: params.i18nInstallAllOnNetwork || 'Install all on network'}
				</button>
			</div>
			{errorMsg && (
				<p className="sd-edi-multisite-notice__error">{errorMsg}</p>
			)}
		</div>
	);
};

export default MultisitePluginNotice;
