import React, { useEffect } from 'react';
import { Button, Skeleton, Switch } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import PluginList from '../../PluginList';
import ModalHeader from '../ModalHeader';

const Setup = ({ modalData, handleImport }) => {
	const {
		excludeImages,
		setExcludeImages,
		reset,
		setReset,
		pluginList,
		fetchPluginList,
		loading,
		setLoading,
		currentStep,
		setCurrentStep,
	} = useSharedDataStore();

	useEffect(() => {
		setLoading(true);
	}, [setLoading]);

	useEffect(() => {
		(async () => {
			try {
				await fetchPluginList('/sd/edi/v1/plugin/list');
			} catch (error) {
				console.error(error);
			}
		})();
	}, [fetchPluginList]);

	const demoPluginData = pluginList.success ? pluginList.data : [];
	const pluginDataArray = Object.entries(demoPluginData).map(
		([key, value]) => ({
			key,
			...value,
		})
	);

	const filteredPluginDataArray =
		Object.keys(modalData?.data?.plugins || {}).length > 0
			? pluginDataArray.filter(
					(plugin) => modalData.data.plugins[plugin.key] !== undefined
			  )
			: pluginDataArray;

	const handlePrevious = () => {
		setCurrentStep(currentStep - 1);
	};

	return (
		<>
			<ModalHeader
				currentStep={currentStep}
				title="Configure your import"
			/>
			<div className="modal-content">
				<div className="import-options">
					<div>
						<h4>Exclude Images</h4>
						<Switch
							checked={excludeImages}
							onChange={(checked) => setExcludeImages(checked)}
						/>
					</div>
					<div>
						<h4>Reset Database</h4>
						<Switch
							checked={reset}
							onChange={(checked) => setReset(checked)}
						/>
					</div>
				</div>
				<div className="required-plugins">
					{loading ? (
						<>
							<Skeleton
								active
								avatar
								paragraph={{ rows: 1, width: '25%' }}
								style={{
									marginBottom: 20,
									paddingBottom: 20,
									borderBottom: '1px solid #ddd',
								}}
							/>
							<Skeleton
								active
								avatar
								paragraph={{ rows: 1, width: '25%' }}
							/>
						</>
					) : (
						<PluginList plugins={filteredPluginDataArray} />
					)}
				</div>
				<div className="step-actions">
					{currentStep > 1 && (
						<Button type="primary" onClick={handlePrevious}>
							Previous
						</Button>
					)}
					<Button type="primary" onClick={handleImport}>
						Import
					</Button>
				</div>
			</div>
		</>
	);
};

export default Setup;
