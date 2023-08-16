import { Row, Col, Button } from 'antd';
import Header from './Layouts/Header';
import DemoCard from './components/DemoCard';
import gridSkeleton from './components/skeleton';
import React, { useState, useEffect } from 'react';
import ErrorMessage from './components/ErrorMessage';
import useSharedDataStore from './utils/sharedDataStore';
import ModalComponent from './components/Modal/ModalComponent';
import Support from './components/Support';

/* global sdEdiAdminParams */

/**
 * The main component representing the demo import application.
 */
const App = () => {
	/**
	 * State hooks.
	 */
	const [modalData, setModalData] = useState(null);
	const [errorMessage, setErrorMessage] = useState('');

	/**
	 * Values from the shared data store.
	 */
	const {
		importList,
		loading,
		fetchImportList,
		modalVisible,
		setModalVisible,
		handleModalCancel,
	} = useSharedDataStore();

	/**
	 * Effect hook to fetch the import list when the component mounts.
	 */
	useEffect(() => {
		(async () => {
			try {
				await fetchImportList('/sd/edi/v1/import/list');
			} catch (error) {
				console.error(error);
			}
		})();
	}, [fetchImportList]);

	/**
	 * Effect hook to set the error message if the import list is not successful.
	 */
	useEffect(() => {
		if (!importList.success) {
			setErrorMessage(importList.message);
		}
	}, [importList]);

	/**
	 * Extracting the demo data from the import list.
	 */
	const demoData =
		importList.success && importList.data && importList.data.demoData;

	/**
	 * Function to show the modal and set the modal data.
	 *
	 * @param {Object} data - The data to be passed to the modal component.
	 */
	const showModal = (data) => {
		setModalVisible(true);
		setModalData(data);
	};

	/**
	 * Handle 'Server Page' button behavior.
	 */
	const handleServerPageBtn = () => {
		const serverPageUrl = sdEdiAdminParams.serverPageUrl;
		window.open(serverPageUrl, '_self');
	};

	let containerClassName = 'edi-container';

	if (!importList.success) {
		if (loading && !demoData) {
			containerClassName += ' loading';
		} else {
			containerClassName += ' no-theme-config';
		}
	} else {
		containerClassName += ' theme-config-found';
	}

	return (
		<>
			<div className="wrap edi-demo-importer-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading="Demo Importer"
				/>

				<div className="edi-content">
					<div className={containerClassName}>
						<Row gutter={[30, 30]}>
							{loading && !demoData ? (
								<>
									{Array.from({
										length: sdEdiAdminParams.numberOfDemos,
									}).map((_, i) => (
										<Col key={i} className="gutter-row">
											<div className="skeleton-wrapper">
												{gridSkeleton(loading)}
											</div>
										</Col>
									))}
								</>
							) : (
								<>
									{!importList.success ? (
										<ErrorMessage message={errorMessage} />
									) : (
										<>
											{Object.keys(demoData).map(
												(key, index) => {
													const demoItem = {
														...demoData[key],
														id: key,
													};

													return (
														<Col
															className="gutter-row edi-demo-card edi-fade-in"
															key={`demo-${index}`}
														>
															<DemoCard
																data={demoItem}
																showModal={
																	showModal
																}
																key={`demo-${index}`}
															/>
														</Col>
													);
												}
											)}
										</>
									)}
								</>
							)}
						</Row>
					</div>
				</div>
				<ModalComponent
					visible={modalVisible}
					onCancel={handleModalCancel}
					modalData={modalData}
				/>
			</div>
			<div className="edi-server-status">
				<Button
					className="edi-server-status-btn"
					type="primary"
					onClick={handleServerPageBtn}
				>
					<span>{sdEdiAdminParams.serverPageBtnText}</span>
				</Button>
			</div>
			{'yes' === sdEdiAdminParams.enableSupportButton && <Support />}
		</>
	);
};

export default App;
