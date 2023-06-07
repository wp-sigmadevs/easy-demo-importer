/**
 * Admin JS Helpers.
 */

import {
	CheckCircleTwoTone,
	CloseCircleTwoTone,
	ExclamationCircleTwoTone,
} from '@ant-design/icons';

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
