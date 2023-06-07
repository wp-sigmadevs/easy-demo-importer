import { Row, Col } from 'antd';
import React, { useState, useEffect } from 'react';
import gridSkeleton from './components/skeleton';
import { usePluginListStore } from './utils/pluginListStore';
import ModalComponent from './components/Modal/ModalComponent';
import DemoCard from './components/DemoCard';
import Header from './Layouts/Header';
import ErrorMessage from './components/ErrorMessage';

/* global sdEdiAdminParams */

const App = () => {
	const { importList, loading, fetchImportList } = usePluginListStore();
	const [modalVisible, setModalVisible] = useState(false);
	const [modalData, setModalData] = useState(null);
	const [errorMessage, setErrorMessage] = useState('');

	useEffect(() => {
		fetchImportList('/sd/edi/v1/import/list');
	}, [fetchImportList]);

	useEffect(() => {
		if (!importList.success) {
			setErrorMessage(importList.message);
		}
	}, [importList]);

	const demoData =
		importList.success && importList.data && importList.data.demoData;

	const showModal = (data) => {
		setModalVisible(true);
		setModalData(data);
	};

	const handleModalCancel = () => {
		setModalVisible(false);
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
