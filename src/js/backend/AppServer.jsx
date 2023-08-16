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
const AppServer = () => {
	/**
	 * Values from the shared data store.
	 */
	// const {
	// 	importList,
	// 	loading,
	// 	fetchImportList,
	// 	modalVisible,
	// 	setModalVisible,
	// 	handleModalCancel,
	// } = useSharedDataStore();

	/**
	 * Effect hook to fetch the import list when the component mounts.
	 */
	// useEffect(() => {
	// 	(async () => {
	// 		try {
	// 			await fetchImportList('/sd/edi/v1/import/list');
	// 		} catch (error) {
	// 			console.error(error);
	// 		}
	// 	})();
	// }, [fetchImportList]);

	/**
	 * Effect hook to set the error message if the import list is not successful.
	 */
	// useEffect(() => {
	// 	if (!importList.success) {
	// 		setErrorMessage(importList.message);
	// 	}
	// }, [importList]);

	/**
	 * Extracting the demo data from the import list.
	 */
	// const demoData =
	// 	importList.success && importList.data && importList.data.demoData;

	return (
		<>
			<div className="wrap edi-server-status-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading="System Status"
				/>

				<div className="edi-content">

				</div>
			</div>
			{'yes' === sdEdiAdminParams.enableSupportButton && <Support />}
		</>
	);
};

export default AppServer;
