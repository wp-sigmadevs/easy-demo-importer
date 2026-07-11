import React, { useState, useEffect } from 'react';
import { Tabs, Button } from 'antd';
import Header from './Layouts/Header';
import Support from './components/Support';
import ServerStatusPanel from './components/ServerStatusPanel';
import ImportLogPanel from './components/ImportLogPanel';

/* global sdEdiAdminParams */

/**
 * Combined System Status + Import Log page. Both used to be separate admin
 * submenu pages; they now live here as two tabs under a single menu item.
 *
 * @param {Object} props            - Component properties.
 * @param {string} props.defaultTab - Tab to open first ('status' | 'log').
 */
const AppStatus = ({ defaultTab = 'status' }) => {
	const [activeTab, setActiveTab] = useState(defaultTab);

	// Switching between the /system_status_page and /import_log routes only
	// changes the hash — React reuses this component instance rather than
	// remounting, so keep the active tab in sync with the route's default.
	useEffect(() => {
		setActiveTab(defaultTab);
	}, [defaultTab]);

	const items = [
		{
			key: 'status',
			label: sdEdiAdminParams.statusTabLabel || 'System Status',
			children: <ServerStatusPanel />,
		},
		{
			key: 'log',
			label: sdEdiAdminParams.logTabLabel || 'Import Log',
			children: <ImportLogPanel />,
		},
	];

	const handleBackBtn = () => {
		window.open(sdEdiAdminParams.importPageUrl, '_self');
	};

	return (
		<>
			<div className="wrap edi-server-status-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading={
						sdEdiAdminParams.statusLogHeading ||
						'System Status & Import Log'
					}
				/>

				<div className="edi-content">
					<div className="edi-container edi-status-log">
						<Tabs
							activeKey={activeTab}
							onChange={setActiveTab}
							items={items}
						/>
					</div>
				</div>
			</div>

			<div className="edi-server-status">
				<Button
					className="edi-server-status-btn"
					type="primary"
					onClick={handleBackBtn}
				>
					<span>{sdEdiAdminParams.importPageBtnText}</span>
				</Button>
			</div>

			{'yes' === sdEdiAdminParams.enableSupportButton && <Support />}
		</>
	);
};

export default AppStatus;
