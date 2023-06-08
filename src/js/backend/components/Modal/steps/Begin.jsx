import React from 'react';
import { Button } from 'antd';
import ModalHeader from '../ModalHeader';
import useSharedDataStore from '../../../utils/sharedDataStore';

/**
 * Component representing the "Begin" step in the modal.
 */
const Begin = () => {
	/**
	 * Values from the shared data store.
	 */
	const { handleReset, currentStep, setCurrentStep } = useSharedDataStore();

	/**
	 * Handles moving to the next step.
	 */
	const onNext = () => {
		setCurrentStep(currentStep + 1);
	};

	return (
		<>
			<ModalHeader currentStep={currentStep} title="Before you proceed" />
			<div className="modal-content">
				<div className="notice">
					<p>
						Before importing this demo, we recommend that you backup
						your site&apos;s data and files. You can use a backup
						plugin plugin like XYZ Backup for WordPress to ensure
						you have a copy of your site in case anything goes wrong
						during the import process.
					</p>
					<p>
						Please note that this demo import will install all the
						required plugins, import contents, settings, customizer
						data, widgets, and other necessary elements to replicate
						the demo site. Make sure to review your existing data
						and settings as they may be overwritten.
					</p>
				</div>
				<div className="step-actions">
					<Button type="primary" onClick={handleReset}>
						Cancel
					</Button>
					<Button type="primary" onClick={onNext}>
						Next
					</Button>
				</div>
			</div>
		</>
	);
};

export default Begin;
