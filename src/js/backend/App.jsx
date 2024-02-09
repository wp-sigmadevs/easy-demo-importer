import Header from './Layouts/Header';
import { Row, Col, Button, Tabs, Skeleton, Input } from 'antd';
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
	const [errorMessage, setErrorMessage] = useState(null);
	const [isModalVisible, setIsModalVisible] = useState(false);
	const [searchQuery, setSearchQuery] = useState('');
	const [filteredDemoData, setFilteredDemoData] = useState(null);
	const [isSearchQueryEmpty, setIsSearchQueryEmpty] = useState(true);

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
	 * Filter demo data based on search query.
	 */
	useEffect(() => {
		if (searchQuery.trim() !== '') {
			const filteredData = Object.values(demoData).filter((demo) => {
				const searchWords = searchQuery.toLowerCase().split(' ');

				return searchWords.every((word) =>
					demo.name.toLowerCase().includes(word)
				);
			});
			setFilteredDemoData(filteredData.length > 0 ? filteredData : null);
		} else {
			setFilteredDemoData(null);
		}
	}, [searchQuery, demoData]);

	useEffect(() => {
		setIsSearchQueryEmpty(searchQuery.trim() === '');
	}, [searchQuery]);

	/**
	 * Extracting the demo data from the import list.
	 */
	const demoData =
		importList.success && importList.data && importList.data.demoData;

	// console.log(demoData)

	/**
	 * Grouping demoData by category.
	 */
	const groupedDemoData = {};
	if (demoData) {
		groupedDemoData.All = Object.values(demoData);

		Object.keys(demoData).forEach((key) => {
			const demo = demoData[key];
			const category = demo.category;
			if (!groupedDemoData[category]) {
				groupedDemoData[category] = [];
			}
			groupedDemoData[category].push(demo);
		});
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

	// Function to handle search input change.
	const handleSearchChange = (e) => {
		setSearchQuery(e.target.value);
	};

	// Function to generate JSX for all demo cards
	const generateAllDemoCards = () => (
		<Row gutter={[30, 30]}>
			{Object.values(demoData).map((demo, index) => (
				<Col
					className="gutter-row edi-demo-card edi-fade-in"
					key={`demo-${index}`}
				>
					<DemoCard data={demo} showModal={showModal} />
				</Col>
			))}
		</Row>
	);

	// Function to generate JSX for filtered demo cards.
	const generateFilteredDemoCards = (demoItems) => (
		<Row gutter={[30, 30]}>
			{demoItems.map((demo, index) => (
				<Col
					className="gutter-row edi-demo-card edi-fade-in"
					key={`demo-${index}`}
				>
					<DemoCard data={demo} showModal={showModal} />
				</Col>
			))}
		</Row>
	);

	const generateTabContent = (category) => {
		if (searchQuery.trim() === '') {
			return (
				groupedDemoData[category] &&
				generateFilteredDemoCards(groupedDemoData[category])
			);
		}
		return filteredDemoData && generateFilteredDemoCards(filteredDemoData);
	};

	/**
	 * Generates JSX for rendering demo cards within a Row component.
	 *
	 * @param {Object} demoItems - The demo items to render as cards.
	 */
	const generateDemoCards = (demoItems) => (
		<Row gutter={[30, 30]}>
			{Object.keys(demoItems).map((key, index) => (
				<Col
					className="gutter-row edi-demo-card edi-fade-in"
					key={`demo-${index}`}
				>
					<DemoCard data={demoItems[key]} showModal={showModal} />
				</Col>
			))}
		</Row>
	);

	return (
		<>
			<div className="wrap edi-demo-importer-wrapper">
				<Header
					logo={sdEdiAdminParams.ediLogo}
					heading="Demo Importer"
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
									{sdEdiAdminParams.hasTabCategories && (
										<>
											<Col className="gutter-row skeleton-col">
												<div className="list-skeleton details">
													<Skeleton
														paragraph={{
															rows: 0,
														}}
														active={true}
													/>
												</div>
											</Col>
										</>
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
										{sdEdiAdminParams.hasTabCategories ? (
											<>
												<div className="edi-nav-wrapper">
													<div className="edi-nav-tabs">
														<div className="edi-nav-search">
															<Input
																placeholder="Search demos..."
																value={
																	searchQuery
																}
																onChange={
																	handleSearchChange
																}
															/>
														</div>
														<Tabs
															defaultActiveKey="All"
															centered
															items={Object.keys(
																groupedDemoData
															).map(
																(category) => ({
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
																})
															)}
														></Tabs>
													</div>
												</div>
											</>
										) : (
											<>
												<div className="edi-nav-wrapper">
													<div className="edi-nav-search"></div>
												</div>
												{/*generateDemoCards(demoData)*/}
												{searchQuery.trim() === ''
													? generateAllDemoCards()
													: filteredDemoData !==
															null &&
													  generateFilteredDemoCards(
															filteredDemoData
													  )}
											</>
										)}
									</>
								)}
							</>
						)}
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
