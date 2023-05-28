import { Row, Col, Button } from 'antd';
import React, { useState, useEffect } from 'react';
import gridSkeleton from './skeleton';
import { useImportListStore } from '../utils/importListStore';
import ModalComponent from './ModalComponent';

/* global sdEdiAdminParams */

const App = () => {
	const { importList, loading, fetchImportList } = useImportListStore();
	const [modalVisible, setModalVisible] = useState(false);
	const [modalData, setModalData] = useState(null);

	useEffect(() => {
		fetchImportList('/sd/edi/v1/import/list');
	}, [fetchImportList]);

	const demoData = importList.data && importList.data.demoData;

	const showModal = (data) => {
		setModalVisible(true);
		setModalData(data);
	};

	const handleModalCancel = () => {
		setModalVisible(false);
	};

	const handleModalOk = () => {
		// Handle the OK button action if needed
	};

	return (
		<>
			<div className="wrap rtdi-demo-importer-wrapper">
				<div className="rtdi-header">
					<h1>Easy Demo Importer</h1>
				</div>
				<div className="rtdi-content">
					<div className="rtdi-container">
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
											xl={6}
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
									{Object.keys(demoData).map((key, index) => (
										<Col
											className="gutter-row edi-demo-card edi-fade-in"
											xs={24}
											sm={24}
											md={12}
											lg={8}
											xl={6}
											key={`demo-${index}`}
										>
											<div className="demo-wrapper">
												<header>
													<img
														src={
															demoData[key]
																.previewImage
														}
														alt="Preview"
													/>
													<a
														className="details"
														target="_blank"
														href={
															demoData[key]
																.previewUrl
														}
														rel="noreferrer"
													>
														Preview
													</a>
												</header>
												<div className="edi-demo-content">
													<div className="demo-name">
														<h2>
															{demoData[key].name}
														</h2>
													</div>
													<div className="edi-demo-actions">
														<Button
															className="edi-modal-button"
															type="primary"
															onClick={() =>
																showModal({
																	id: key,
																	data: demoData[
																		key
																	],
																	reset: true,
																	excludeImages: true,
																})
															}
														>
															Import
														</Button>
													</div>
												</div>
											</div>
										</Col>
									))}
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
