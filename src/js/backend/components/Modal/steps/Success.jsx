import React from 'react';
import { Result, Button } from 'antd';
import { ExportOutlined, CloseOutlined, RedoOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing the import success or failure message.
 *
 * @param {boolean}  importComplete - Flag indicating whether the import was completed.
 * @param {Function} handleReset    - Function to handle the reset action.
 * @param {string}   message        - Import messages.
 */
const Success = ({ importComplete, handleReset, message }) => {
	if (importComplete) {
		/**
		 * Handle 'View Site' button behavior.
		 */
		const handleViewSite = () => {
			const homeUrl = sdEdiAdminParams.homeUrl;
			window.open(homeUrl, '_blank');
		};

		return (
			<>
				<div className="ant-result ant-result-success">
					<div className="ant-result-icon">
						<span
							role="img"
							aria-label="check-circle"
							className="custom-icon"
						>
							<div className="check-container">
								<div className="check-background">
									<svg
										viewBox="0 0 65 51"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<path
											d="M7 25L27.3077 44L58.5 7"
											stroke="white"
											strokeWidth="13"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								</div>
								<div className="check-shadow"></div>
							</div>
						</span>
					</div>
					<div className="ant-result-title">
						<h3>{message}</h3>
					</div>
					<div className="ant-result-extra edi-d-flex edi-justify-content-center">
						<Button key="close" onClick={handleReset}>
							<CloseOutlined />
							<span>{sdEdiAdminParams.btnClose}</span>
						</Button>
						<Button
							key="view-site"
							type="primary"
							onClick={handleViewSite}
						>
							<span>{sdEdiAdminParams.btnViewSite}</span>
							<ExportOutlined />
						</Button>
					</div>
				</div>
			</>
		);
	}

	return (
		<Result
			status="error"
			title={message}
			extra={[
				<Button key="close" onClick={handleReset}>
					<CloseOutlined />
					<span>Close</span>
				</Button>,
				<Button
					key="retry"
					type="primary"
					onClick={() => window.location.reload()}
				>
					<RedoOutlined />
					<span>Reload & Retry</span>
				</Button>,
			]}
		/>
	);
};

export default Success;
