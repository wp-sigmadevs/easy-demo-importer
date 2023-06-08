import { Row, Col } from 'antd';
import Header from './Layouts/Header';
import DemoCard from './components/DemoCard';
import gridSkeleton from './components/skeleton';
import React, { useState, useEffect } from 'react';
import ErrorMessage from './components/ErrorMessage';
import useSharedDataStore from './utils/sharedDataStore';
import ModalComponent from './components/Modal/ModalComponent';

/* global sdEdiAdminParams */

/**
 * The main component representing the demo import application.
 */
const App = () => {
	/**
	 * State hooks
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

	return (
		<>
			<div className="wrap edi-demo-importer-wrapper">
				<Header logo={sdEdiAdminParams.ediLogo} />

				<div className="edi-content">
					<div className="edi-container">
						<Row gutter={[30, 30]}>
							{loading && !demoData ? (
								<>
									{Array.from({
										length: sdEdiAdminParams.numberOfDemos,
									}).map((_, i) => (
										<Col
											className="gutter-row"
											xs={24}
											sm={24}
											md={12}
											lg={8}
											xl={8}
											key={i}
										>
											<div
												style={{
													border: '1px solid #ccc',
												}}
											>
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
															xs={24}
															sm={24}
															md={12}
															lg={8}
															xl={8}
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
		</>
	);
};

export default App;
