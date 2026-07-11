import React, { useState, useEffect } from 'react';
import { Row, Col, Skeleton } from 'antd';
import ErrorMessage from './ErrorMessage';
import ServerInfoCollapse from './ServerInfoCollapse';
import useSharedDataStore from '../utils/sharedDataStore';

/**
 * The System Status tab — server/environment report. Fetches its own data and
 * tracks its own loading state (independent of the sibling Import Log tab).
 *
 * Keeps the original System Status markup — the `.edi-container.server-container`
 * wrapper, its state modifier classes, and the Row/Col structure — because all
 * of the panel styling (per-section icons, the 600px centred column, spacing)
 * is scoped to those classes.
 */
const ServerStatusPanel = () => {
	const [loading, setLoading] = useState(true);
	const [errorMessage, setErrorMessage] = useState('');
	const { serverData, fetchServerData } = useSharedDataStore();

	useEffect(() => {
		(async () => {
			try {
				await fetchServerData('/sd/edi/v1/server/status');
			} catch (error) {
				console.error(error);
			} finally {
				setLoading(false);
			}
		})();
	}, [fetchServerData]);

	useEffect(() => {
		if (serverData && serverData.success === false) {
			setErrorMessage(serverData.message);
		}
	}, [serverData]);

	const serverInfo = serverData.success && serverData.data;

	let containerClassName = 'edi-container server-container';

	if (!serverData.success) {
		containerClassName +=
			loading && !serverInfo ? ' loading' : ' no-server-config';
	} else {
		containerClassName += ' server-config-found';
	}

	return (
		<div className={containerClassName}>
			<Row gutter={[30, 30]}>
				{loading && !serverInfo ? (
					<Col className="gutter-row">
						<div className="skeleton-wrapper">
							{Array.from({ length: 6 }).map((_, i) => (
								<div className="list-skeleton details" key={i}>
									<Skeleton
										avatar
										paragraph={{ rows: 0 }}
										active
									/>
								</div>
							))}
						</div>
					</Col>
				) : !serverData.success ? (
					<ErrorMessage message={errorMessage} />
				) : (
					<Col className="gutter-row edi-server-info edi-fade-in">
						<ServerInfoCollapse serverInfo={serverInfo} />
					</Col>
				)}
			</Row>
		</div>
	);
};

export default ServerStatusPanel;
