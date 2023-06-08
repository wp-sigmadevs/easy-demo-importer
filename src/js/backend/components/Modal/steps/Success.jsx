import React from 'react';
import { Result, Button } from 'antd';

/**
 * Component representing the import success or failure message.
 *
 * @param {boolean}  importSuccess - Flag indicating whether the import was successful.
 * @param {Function} handleReset   - Function to handle the reset action.
 */
const Success = ({ importSuccess, handleReset }) => {
	if (importSuccess) {
		return (
			<Result
				status="success"
				title="Import Completed Successfully"
				extra={[
					<Button key="view-site" type="primary">
						View Site
					</Button>,
					<Button key="close" onClick={handleReset}>
						Close
					</Button>,
				]}
			/>
		);
	}

	return (
		<Result
			status="error"
			title="Import Failed"
			extra={[
				<Button
					key="retry"
					type="primary"
					onClick={() => window.location.reload()}
				>
					Reload & Retry
				</Button>,
				<Button key="close" onClick={handleReset}>
					Close
				</Button>,
			]}
		/>
	);
};

export default Success;
