import Header from './Layouts/Header';
import {Row, Col, Skeleton, Button} from 'antd';
import Support from './components/Support';
import React, { useState, useEffect } from 'react';
import ErrorMessage from './components/ErrorMessage';
import useSharedDataStore from './utils/sharedDataStore';
import ServerInfoCollapse from './components/ServerInfoCollapse';

/* global sdEdiAdminParams */

/**
 * The main component representing the server info application.
 */
const AppServer = () => {
	/**
	 * State hooks.
	 */
	const [errorMessage, setErrorMessage] = useState('');

	/**
	 * Values from the shared data store.
	 */
	const { serverData, loading, fetchServerData } = useSharedDataStore();

	/**
	 * Effect hook to fetch the server data when the component mounts.
	 */
	useEffect(() => {
		(async () => {
			try {
				await fetchServerData('/sd/edi/v1/server/status');
			} catch (error) {
				console.error(error);
			}
		})();
	}, [fetchServerData]);

	/**
	 * Effect hook to set the error message if the server data is not successful.
	 */
	useEffect(() => {
		if (!serverData.success) {
			setErrorMessage(serverData.message);
		}
	}, [serverData]);

	/**
	 * Extracting the server data.
	 */
	const serverInfo = serverData.success && serverData.data;

	let containerClassName = 'edi-container server-container';

	if (!serverData.success) {
		if (loading && !serverInfo) {
			containerClassName += ' loading';
		} else {
			containerClassName += ' no-server-config';
		}
	} else {
		containerClassName += ' server-config-found';
	}

	const handleImportPageBtn = () => {
		const importPageUrl = sdEdiAdminParams.importPageUrl;
		window.open(importPageUrl, '_self');
	};

	return (
		<>
			<div className="wrap edi-server-status-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading="System Status"
				/>

				<div className="edi-content">
					<div className={containerClassName}>
						<Row gutter={[30, 30]}>
							{loading && !serverInfo ? (
								<>
									<Col className="gutter-row">
										<div className="skeleton-wrapper">
											{Array.from({length: 6}).map(
												(_, buttonIndex) => (
													<div
														className="list-skeleton details"
														key={buttonIndex}
													>
														<Skeleton
															avatar
															paragraph={{
																rows: 0,
															}}
															active={true}
														/>
													</div>
												)
											)}
										</div>
									</Col>
								</>
							) : (
								<>
									{!serverData.success ? (
										<ErrorMessage message={errorMessage}/>
									) : (
										<>
											<Col className="gutter-row edi-server-info edi-fade-in">
												<ServerInfoCollapse
													serverInfo={serverInfo}
												/>
											</Col>
										</>
									)}
								</>
							)}
						</Row>
					</div>
				</div>
			</div>

			<div className="edi-server-status">
				<Button
					className="edi-server-status-btn"
					type="primary"
					onClick={handleImportPageBtn}
				>
					<span>{sdEdiAdminParams.importPageBtnText}</span>
				</Button>
			</div>

			{'yes' === sdEdiAdminParams.enableSupportButton && <Support/>}
		</>
	);
};

export default AppServer;
