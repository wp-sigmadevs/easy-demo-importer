import { Row, Col, Button, Image, Space } from 'antd';
import React, { useState, useEffect } from 'react';
import gridSkeleton from './skeleton';
import { usePluginListStore } from '../utils/pluginListStore';
import ModalComponent from './ModalComponent';
import { DownloadOutlined, FullscreenOutlined } from '@ant-design/icons';

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
				<div className="edi-header">
					<img src={sdEdiAdminParams.ediLogo} alt="EDI Logo" />
					<h1>Demo Importer</h1>
				</div>
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
										<>
											<Col>
												{errorMessage && (
													<div className="error-message">
														{errorMessage}
													</div>
												)}
											</Col>
										</>
									) : (
										<>
											{Object.keys(demoData).map(
												(key, index) => (
													<Col
														className="gutter-row edi-demo-card edi-fade-in"
														xs={24}
														sm={24}
														md={12}
														lg={8}
														xl={8}
														key={`demo-${index}`}
													>
														<div className="demo-wrapper">
															<header>
																{/*<image*/}
																{/*	src={*/}
																{/*	// 	demoData[*/}
																{/*	// 		key*/}
																{/*	// 	]*/}
																{/*	// 		.previewImage*/}
																{/*	// }*/}
																{/*	alt="Preview"*/}
																{/*/>*/}
																<Image
																	src={
																		demoData[
																			key
																		]
																			.previewImage
																	}
																	preview={{
																		maskClassName:
																			'custom-mask',
																		mask: (
																			<Space>
																				<FullscreenOutlined />
																				Click to Enlarge
																			</Space>
																		),
																	}}
																	alt="Preview"
																/>
																<a
																	className="details"
																	target="_blank"
																	href={
																		demoData[
																			key
																		]
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
																		{
																			demoData[
																				key
																			]
																				.name
																		}
																	</h2>
																</div>
																<div className="edi-demo-actions">
																	<Button
																		className="edi-modal-button"
																		type="primary"
																		onClick={() =>
																			showModal(
																				{
																					id: key,
																					data: demoData[
																						key
																					],
																					reset: true,
																					excludeImages: true,
																				}
																			)
																		}
																	>
																		Import <DownloadOutlined />
																	</Button>
																</div>
															</div>
														</div>
													</Col>
												)
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
