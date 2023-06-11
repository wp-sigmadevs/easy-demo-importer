import React from 'react';
import { Button } from 'antd';
import ModalHeader from '../ModalHeader';
import useSharedDataStore from '../../../utils/sharedDataStore';
import { ArrowRightOutlined, CloseOutlined } from '@ant-design/icons';

/**
 * Component representing the "Begin" step in the modal.
 *
 * @param {Function} handleReset - Handles resetting the modal.
 */
const Begin = ({ handleReset }) => {
	/**
	 * Values from the shared data store.
	 */
	const { currentStep, setCurrentStep } = useSharedDataStore();

	/**
	 * Handles moving to the next step.
	 */
	const onNext = () => {
		setCurrentStep(currentStep + 1);
	};

	return (
		<>
			<ModalHeader currentStep={currentStep} />
			<div className="modal-content-inner">
				<h3>Before You Proceed</h3>
				<div className="import-notice">
					<p>
						Before importing demo data, we recommend that you backup
						your site&apos;s data and files. You can use a popular
						backup plugin to ensure you have a copy of your site in
						case anything goes wrong during the import process.
					</p>
					<p>
						Please note that this demo import will install all the
						required plugins, import contents, media, settings,
						customizer data, widgets, and other necessary elements
						to replicate the demo site. Make sure to review your
						existing data and settings as they may be overwritten.
					</p>
				</div>
				<div className="step-actions">
					<Button type="primary" onClick={handleReset}>
						<CloseOutlined />
						<span>Cancel</span>
					</Button>
					<Button type="primary" onClick={onNext}>
						<span>Continue</span>
						<ArrowRightOutlined />
					</Button>
				</div>
			</div>
		</>
	);
};

export default Begin;
