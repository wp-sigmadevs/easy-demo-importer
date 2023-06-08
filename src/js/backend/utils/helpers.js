/**
 * Admin JS Helpers.
 */

import {
	CheckCircleTwoTone,
	CloseCircleTwoTone,
	ExclamationCircleTwoTone,
} from '@ant-design/icons';

/**
 * Get the corresponding status icon based on the provided status.
 *
 * @param {string} status - The status value to determine the icon.
 */
export const getStatusIcon = (status) => {
	switch (status) {
		case 'install':
			return <CloseCircleTwoTone twoToneColor="#eb2f96" />;
		case 'active':
			return <CheckCircleTwoTone twoToneColor="#52c41a" />;
		case 'inactive':
			return <ExclamationCircleTwoTone twoToneColor="#faad14" />;
		default:
			return null;
	}
};

/**
 * Get the corresponding plugin text based on the provided status.
 *
 * @param {string} status - The status value to determine the plugin text.
 */
export const getPluginText = (status) => {
	switch (status) {
		case 'install':
			return 'Not Installed';
		case 'active':
			return 'Installed and Active';
		case 'inactive':
			return 'Installed but Not Active';
		default:
			return null;
	}
};

/**
 * Get the current status for a step in the import process.
 *
 * @param {number}  currentStep   - The current step in the import process.
 * @param {boolean} importSuccess - The import success status.
 * @param {number}  index         - The index of the step.
 */
export const getCurrentStatus = (currentStep, importSuccess, index) => {
	if (index === currentStep - 1) {
		return 'process';
	} else if (index < currentStep) {
		return 'finish';
	} else if (index === 3 && importSuccess) {
		return 'finish';
	}

	return 'wait';
};

/**
 * Get the progress steps for the import process.
 */
export const progressSteps = () => {
	return [
		{
			title: 'Begin',
		},
		{
			title: 'Setup',
		},
		{
			title: 'Imports',
		},
		{
			title: 'Success',
		},
	];
};
