import React from 'react';
import ModalHeader from '../ModalHeader';

const Begin = () => {
	return (
		<>
			<ModalHeader currentStep={1} />
			<div className="notice">
				<p>
					Before importing this demo, we recommend that you backup your site's data and files. You can use a backup plugin like XYZ Backup for WordPress to ensure you have a copy of your site in case anything goes wrong during the import process.
				</p>
				<p>
					Please note that this demo import will install all the required plugins, import contents, settings, customizer data, widgets, and other necessary elements to replicate the demo site. Make sure to review your existing data and settings as they may be overwritten.
				</p>
			</div>
			<div className="step-actions">
				<Button type="primary" onClick={onCancel}>
					Cancel
				</Button>
				<Button type="primary" onClick={() => setCurrentStep(2)}>
					Next
				</Button>
			</div>
		</>
	);
};

export default Begin;
