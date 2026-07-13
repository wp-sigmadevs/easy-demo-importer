import ModalHeader from '../ModalHeader';
import React, { useEffect } from 'react';
import PluginList from '../../PluginList';
import PreflightPanel from '../../PreflightPanel';
import { Button, Col, Row, Skeleton } from 'antd';
import useSharedDataStore from '../../../utils/sharedDataStore';
import {
	ArrowLeftOutlined,
	ArrowRightOutlined,
	CloseOutlined,
} from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Readiness step: the required-plugins list and the pre-import environment
 * checklist, shown together before the user configures the import. Splitting
 * these "what's needed / will this work" diagnostics onto their own step keeps
 * the Configure step focused on choices, and gives the checklist room to stay
 * open instead of being crammed under the options.
 *
 * @param {Object}   props             - Component props.
 * @param {Object}   props.modalData   - Data for the modal (selected demo).
 * @param {Function} props.handleReset - Closes/resets the modal.
 */
const Readiness = ({ modalData, handleReset }) => {
	const {
		pluginList,
		fetchPluginList,
		loading,
		setLoading,
		currentStep,
		setCurrentStep,
		preflightData,
		fetchPreflightData,
	} = useSharedDataStore();

	useEffect(() => {
		setLoading(true);
	}, [setLoading]);

	useEffect(() => {
		(async () => {
			try {
				await fetchPluginList('/sd/edi/v1/plugin/list');
			} catch {
				// The plugin list is non-blocking; leave it empty on failure.
			}
		})();
	}, [fetchPluginList]);

	// fetchPreflightData stores an error shape and never rejects, so no catch.
	useEffect(() => {
		fetchPreflightData('/sd/edi/v1/preflight');
	}, [fetchPreflightData]);

	/**
	 * Readiness checks and whether a blocking check failed. While the report is
	 * still loading (or if it errored), the import is never blocked.
	 */
	const preflight = preflightData?.success && preflightData.data;
	const preflightChecks = preflight ? preflight.checks : [];
	const importBlocked = preflight ? !preflight.canProceed : false;

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

	const handleContinue = () => {
		setCurrentStep(currentStep + 1);
	};

	return (
		<>
			<ModalHeader currentStep={currentStep} />

			<div className="modal-content-inner">
				<Row gutter={[30, 30]}>
					<Col
						className="gutter-row"
						xs={24}
						sm={24}
						md={12}
						lg={12}
						xl={12}
					>
						<div className="required-plugins">
							<h3>{sdEdiAdminParams.requiredPluginsTitle}</h3>
							<p>{sdEdiAdminParams.requiredPluginsIntro}</p>
							{loading ? (
								<div className="skeleton-list">
									<Skeleton
										active
										avatar
										paragraph={{ rows: 1, width: '25%' }}
										style={{
											borderBottom:
												'1px solid rgba(5, 5, 5, 0.06)',
										}}
									/>
									<Skeleton
										active
										avatar
										paragraph={{ rows: 1, width: '25%' }}
									/>
								</div>
							) : (
								<PluginList plugins={filteredPluginDataArray} />
							)}
						</div>
					</Col>
					<Col
						className="gutter-row readiness-col"
						xs={24}
						sm={24}
						md={12}
						lg={12}
						xl={12}
					>
						<div className="readiness-checks">
							<h3>
								{sdEdiAdminParams.readinessTitle ||
									'Environment Readiness'}
							</h3>
							<p>
								{sdEdiAdminParams.readinessIntro ||
									'These checks confirm your server can run the import. Resolve any blocking issue before you continue.'}
							</p>
							{preflightChecks.length > 0 ? (
								<PreflightPanel
									checks={preflightChecks}
									blocked={importBlocked}
								/>
							) : (
								<div className="skeleton-list">
									<Skeleton active paragraph={{ rows: 4 }} />
								</div>
							)}
						</div>
					</Col>
				</Row>

				<div className="step-actions">
					<div className="actions-left">
						<Button type="primary" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnCancel}</span>
						</Button>
					</div>
					<div className="actions-right edi-d-flex edi-align-items-center">
						<Button type="primary" onClick={handlePrevious}>
							<ArrowLeftOutlined />
							<span>{sdEdiAdminParams.btnPrevious}</span>
						</Button>
						<Button
							type="primary"
							onClick={handleContinue}
							disabled={importBlocked}
						>
							<span>{sdEdiAdminParams.btnContinue}</span>
							<ArrowRightOutlined />
						</Button>
					</div>
				</div>
			</div>
		</>
	);
};

export default Readiness;
