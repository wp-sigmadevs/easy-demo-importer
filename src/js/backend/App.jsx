import Header from './Layouts/Header';
import { Row, Col, Button } from 'antd';
import Support from './components/Support';
import DemoCard from './components/DemoCard';
import React, { useState, useEffect } from 'react';
import GridSkeleton from './components/GridSkeleton';
import ErrorMessage from './components/ErrorMessage';
import useSharedDataStore from './utils/sharedDataStore';
import ModalComponent from './components/Modal/ModalComponent';
import ModalRequirements from './components/Modal/ModaRequirements';

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
	const [isModalVisible, setIsModalVisible] = useState(false);

	/**
	 * Values from the shared data store.
	 */
	const {
		importList,
		serverData,
		loading,
		fetchImportList,
		fetchServerData,
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
	 * Effect hook to handle server data response and potential errors.
	 */
	useEffect(() => {
		if (!serverData.success) {
			setErrorMessage(serverData.message);
		}

		if (hasErrors(serverInfo)) {
			setIsModalVisible(true);
		}
	}, [serverData, serverInfo]);

	/**
	 * Extracting the demo data from the import list.
	 */
	const demoData =
		importList.success && importList.data && importList.data.demoData;

	/**
	 * Extracting the server data.
	 */
	const serverInfo = serverData.success && serverData.data;

	/**
	 * Checks for errors in the provided server information object.
	 *
	 * @param {Object} info - The server information object.
	 */
	const hasErrors = (info) => {
		for (const sectionKey in info) {
			if (Object.prototype.hasOwnProperty.call(info, sectionKey)) {
				const section = info[sectionKey];
				if (section && section.fields) {
					for (const fieldKey in section.fields) {
						if (
							Object.prototype.hasOwnProperty.call(
								section.fields,
								fieldKey
							)
						) {
							const field = section.fields[fieldKey];
							if (field && field.error) {
								return true; // Found an error
							}
						}
					}
				}
			}
		}
		return false; // No errors found
	};

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

	/**
	 * Handle modal close button behavior.
	 */
	const handleCloseModal = () => {
		setIsModalVisible(false);
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

				{hasErrors(serverInfo) && (
					<ModalRequirements
						isVisible={isModalVisible}
						onClose={handleCloseModal}
						onProceed={handleServerPageBtn}
					/>
				)}

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
												{GridSkeleton(loading)}
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

			{importList.success && (
				<div className="edi-server-status">
					<Button
						className="edi-server-status-btn"
						type="primary"
						onClick={handleServerPageBtn}
					>
						<span>{sdEdiAdminParams.serverPageBtnText}</span>
					</Button>
				</div>
			)}

			{'yes' === sdEdiAdminParams.enableSupportButton &&
				importList.success && <Support />}
		</>
	);
};

export default App;
