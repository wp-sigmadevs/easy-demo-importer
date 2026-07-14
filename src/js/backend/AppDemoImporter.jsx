import Axios from 'axios';
import Header from './Layouts/Header';
import Support from './components/Support';
import DemoCard from './components/DemoCard';
import { useShallow } from 'zustand/react/shallow';
import React, { useState, useEffect, useRef } from 'react';
import GridSkeleton from './components/GridSkeleton';
import ErrorMessage from './components/ErrorMessage';
import RestorePointBanner from './components/RestorePointBanner';
import ManualImportModal from './components/Modal/ManualImportModal';
import useSharedDataStore from './utils/sharedDataStore';
import ModalComponent from './components/Modal/ModalComponent';
import ModalRequirements from './components/Modal/ModaRequirements';
import { Row, Col, Button, Tabs, Skeleton, Input, Empty } from 'antd';
import { UploadOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * The main component representing the demo import application.
 *
 * @return {JSX.Element} - JSX element representing the main application component.
 */
const AppDemoImporter = () => {
	/**
	 * State hooks and initialization.
	 */
	const [modalData, setModalData] = useState(null);
	const [errorMessage, setErrorMessage] = useState(null);
	const [isModalVisible, setIsModalVisible] = useState(false);
	const [manualVisible, setManualVisible] = useState(false);
	const resetStore = useSharedDataStore((state) => state.resetStore);

	/**
	 * Ensures the interrupted-import prompt auto-opens only once per page load.
	 * Dismissing the prompt intentionally keeps resumeRequest, so without this
	 * guard the modal would immediately re-open every time it is closed.
	 */
	const resumeHandledRef = useRef(false);

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
		resumeRequest,
		searchQuery,
		setSearchQuery,
		filteredDemoData,
		setFilteredDemoData,
		isSearchQueryEmpty,
		setIsSearchQueryEmpty,
	} = useSharedDataStore(
		useShallow((state) => ({
			importList: state.importList,
			serverData: state.serverData,
			loading: state.loading,
			fetchImportList: state.fetchImportList,
			fetchServerData: state.fetchServerData,
			modalVisible: state.modalVisible,
			setModalVisible: state.setModalVisible,
			handleModalCancel: state.handleModalCancel,
			resumeRequest: state.resumeRequest,
			searchQuery: state.searchQuery,
			setSearchQuery: state.setSearchQuery,
			filteredDemoData: state.filteredDemoData,
			setFilteredDemoData: state.setFilteredDemoData,
			isSearchQueryEmpty: state.isSearchQueryEmpty,
			setIsSearchQueryEmpty: state.setIsSearchQueryEmpty,
		}))
	);

	/**
	 * Destructure the Search component from the Input module.
	 */
	const { Search } = Input;

	/**
	 * Reset state variables when the component mounts or the route changes.
	 */
	useEffect(() => {
		resetStore();
	}, [resetStore]);

	/**
	 * Fetch the import list when the component mounts.
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
	 * Auto-open the interrupted-import prompt after a reload.
	 *
	 * A previous import that was interrupted (page reload, timeout) leaves a
	 * persisted resumeRequest in the store. Once the demo list is available,
	 * re-open that demo's modal so ModalComponent surfaces the Resume / Start
	 * Over prompt automatically — otherwise the user lands on the plain grid
	 * with no indication an import is still incomplete.
	 */
	useEffect(() => {
		if (resumeHandledRef.current || !importList.success) {
			return;
		}

		const demos = importList.data && importList.data.demoData;
		const resumeId = resumeRequest && resumeRequest.demo;

		if (demos && resumeId && demos[resumeId]) {
			resumeHandledRef.current = true;

			// The page was reloaded mid-import, so the import is no longer running.
			// Tell the server to flag this session interrupted now, so the Import Log
			// reflects it immediately instead of after the heartbeat times out.
			// Resuming refreshes the heartbeat back to live. Fire-and-forget.
			if (resumeRequest.sessionId) {
				const params = new FormData();
				params.append('action', 'sd_edi_mark_interrupted');
				params.append('sd_edi_nonce', sdEdiAdminParams.sd_edi_nonce);
				// Bootstrap requires 'demo' in POST to load the Ajax\Backend classes.
				params.append('demo', resumeId);
				params.append('sessionId', resumeRequest.sessionId);
				Axios.post(sdEdiAdminParams.ajaxUrl, params).catch(() => {});
			}

			setModalVisible(true);
			// Match the shape DemoCard passes to showModal so the modal title and
			// preview resolve (modalData.data.name), and Start Over behaves normally.
			setModalData({
				id: resumeId,
				data: demos[resumeId],
				reset: true,
				excludeImages: true,
			});
		}
	}, [importList, resumeRequest, setModalVisible]);

	/**
	 * Set the error message if the import list is not successful.
	 */
	useEffect(() => {
		if (!importList.success) {
			setErrorMessage(importList.message);
		}
	}, [importList]);

	/**
	 * Fetch the server data when the component mounts.
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
	 * Handle server data response and potential errors.
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
	 * Filter demo data based on search query.
	 */
	useEffect(() => {
		if (searchQuery.trim() !== '') {
			const filteredData = Object.keys(demoData)
				.filter((key) => {
					const demo = demoData[key];
					const searchWords = searchQuery.toLowerCase().split(' ');
					return searchWords.every((word) =>
						demo.name.toLowerCase().includes(word)
					);
				})
				.map((key) => ({ ...demoData[key], id: key }));

			setFilteredDemoData(filteredData.length > 0 ? filteredData : null);
		} else {
			setFilteredDemoData(null);
		}
	}, [searchQuery, setFilteredDemoData, demoData]);

	/**
	 * Updates the search state.
	 */
	useEffect(() => {
		setIsSearchQueryEmpty(searchQuery.trim() === '');
	}, [searchQuery, setIsSearchQueryEmpty]);

	/**
	 * Extracting the demo data from the import list.
	 */
	const demoData =
		importList.success && importList.data && importList.data.demoData;

	/**
	 * Grouping demoData by category.
	 */
	const groupedDemoData = {};

	if (demoData) {
		groupedDemoData.All = Object.keys(demoData).map((key) => ({
			...demoData[key],
			id: key,
		}));

		if (sdEdiAdminParams.hasTabCategories) {
			Object.keys(demoData).forEach((key) => {
				const demo = demoData[key];
				const category = demo.category;

				if (!groupedDemoData[category]) {
					groupedDemoData[category] = [];
				}

				groupedDemoData[category].push({ ...demo, id: key });
			});
		}
	}

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
								return true;
							}
						}
					}
				}
			}
		}

		return false;
	};

	/**
	 * Shows the modal and sets the modal data.
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

	/**
	 * Handles changes in the search input field
	 * by updating the search query state.
	 *
	 * @param {Object} e - The event object representing the change in the search input field.
	 */
	const handleSearchChange = (e) => {
		setSearchQuery(e.target.value);
	};

	/**
	 * Generates demo cards for all demo data items.
	 *
	 * @return {JSX.Element} - JSX element representing the demo cards for all demo data items wrapped in a Row component.
	 */
	const generateAllDemoCards = () => (
		<Row gutter={[30, 30]}>
			{Object.keys(demoData).map((key, index) => {
				const demoItem = {
					...demoData[key],
					id: key,
				};

				return (
					<Col
						className="gutter-row edi-demo-card edi-fade-in"
						key={`demo-${index}`}
					>
						<DemoCard data={demoItem} showModal={showModal} />
					</Col>
				);
			})}
		</Row>
	);

	/**
	 * Generates JSX for rendering demo cards within a Row component.
	 *
	 * @param {Object} demoItems - The demo items to render as cards.
	 * @return {JSX.Element} - JSX element representing the filtered demo cards wrapped in a Row component.
	 */
	const generateFilteredDemoCards = (demoItems) => (
		<Row gutter={[30, 30]}>
			{demoItems.map((demo, index) => {
				const demoItem = {
					...demo,
					id: demo.id,
				};

				return (
					<Col
						className="gutter-row edi-demo-card edi-fade-in"
						key={`demo-${index}`}
					>
						<DemoCard data={demoItem} showModal={showModal} />
					</Col>
				);
			})}
		</Row>
	);

	/**
	 * Generate tab content based on the provided category and search query.
	 *
	 * @param {string} category - The category for which to generate tab content.
	 * @return {JSX.Element | null} - The generated tab content.
	 */
	const generateTabContent = (category) => {
		if (searchQuery.trim() === '') {
			return (
				groupedDemoData[category] &&
				generateFilteredDemoCards(groupedDemoData[category])
			);
		}

		const filteredData =
			filteredDemoData ||
			Object.values(demoData).filter((demo) => {
				const searchWords = searchQuery.toLowerCase().split(' ');

				return searchWords.every((word) =>
					demo.name.toLowerCase().includes(word)
				);
			});

		if (filteredData.length === 0) {
			return <Empty description={sdEdiAdminParams.searchNoResults} />;
		}

		return generateFilteredDemoCards(filteredData);
	};

	return (
		<>
			<div className="wrap edi-demo-importer-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading="Demo Importer"
				/>

				<RestorePointBanner />

				<div className="edi-manual-import-bar">
					<span className="edi-manual-import-hint">
						{sdEdiAdminParams.manualImportHint ||
							'Have your own export? Import it manually.'}
					</span>
					<Button
						icon={<UploadOutlined />}
						onClick={() => setManualVisible(true)}
					>
						{sdEdiAdminParams.manualImportButton || 'Manual Import'}
					</Button>
				</div>

				<ManualImportModal
					visible={manualVisible}
					onClose={() => setManualVisible(false)}
				/>

				{importList.success && hasErrors(serverInfo) && (
					<ModalRequirements
						isVisible={isModalVisible}
						onClose={handleCloseModal}
						onProceed={handleServerPageBtn}
					/>
				)}

				<div className="edi-content">
					<div className={containerClassName}>
						{loading && !demoData ? (
							<Row gutter={[30, 30]}>
								<>
									{!sdEdiAdminParams.removeTabsAndSearch && (
										<Col
											className={`gutter-row skeleton-col ${
												sdEdiAdminParams.hasTabCategories
													? 'has-categories'
													: 'no-categories'
											}`}
										>
											<div className="list-skeleton details">
												<Skeleton
													paragraph={{
														rows: 0,
													}}
													active={true}
												/>
												<Skeleton
													paragraph={{
														rows: 0,
													}}
													active={true}
												/>
											</div>
										</Col>
									)}

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
							</Row>
						) : (
							<>
								{!importList.success ? (
									<ErrorMessage message={errorMessage} />
								) : (
									<>
										{!sdEdiAdminParams.removeTabsAndSearch ? (
											<div className="edi-nav-wrapper">
												<div className="edi-nav-tabs">
													<div className="edi-nav-search">
														<Search
															placeholder={
																sdEdiAdminParams.searchPlaceholder
															}
															value={searchQuery}
															onChange={
																handleSearchChange
															}
															allowClear
															enterButton
															size="large"
														/>
													</div>
													<Tabs
														defaultActiveKey="All"
														centered
														items={Object.keys(
															groupedDemoData
														).map((category) => ({
															label:
																category ===
																'All'
																	? sdEdiAdminParams.allDemoBtnText
																	: category,
															key: category,
															children:
																generateTabContent(
																	category
																),
															disabled:
																!isSearchQueryEmpty,
														}))}
													></Tabs>
												</div>
											</div>
										) : (
											<>{generateAllDemoCards()}</>
										)}
									</>
								)}
							</>
						)}
					</div>
				</div>

				<ModalComponent
					visible={modalVisible}
					onCancel={() => {
						handleModalCancel();
						resetStore();
					}}
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

export default AppDemoImporter;
