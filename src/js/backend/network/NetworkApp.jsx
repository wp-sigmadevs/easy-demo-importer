import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import Dashboard from './Dashboard.jsx';
import ConfigEditor from './ConfigEditor.jsx';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

function NetworkApp() {
	const [tab, setTab] = useState('dashboard');

	return (
		<div className="sd-edi-network">
			<header className="sd-edi-network__header">
				<img src={params.logo} alt="" className="sd-edi-network__logo" />
				<nav className="sd-edi-network__tabs">
					<button
						className={tab === 'dashboard' ? 'is-active' : ''}
						onClick={() => setTab('dashboard')}
					>
						{i18n.dashboard}
					</button>
					<button
						className={tab === 'config' ? 'is-active' : ''}
						onClick={() => setTab('config')}
					>
						{i18n.networkConfig}
					</button>
				</nav>
			</header>
			<main className="sd-edi-network__body">
				{tab === 'dashboard' && <Dashboard />}
				{tab === 'config' && <ConfigEditor />}
			</main>
		</div>
	);
}

const mount = document.getElementById('sd-edi-network-app');
if (mount) {
	createRoot(mount).render(<NetworkApp />);
}
