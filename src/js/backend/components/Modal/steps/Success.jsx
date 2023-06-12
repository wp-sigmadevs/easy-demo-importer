import React from 'react';
import { Result, Button } from 'antd';
import { ExportOutlined, CloseOutlined, RedoOutlined } from '@ant-design/icons';

/**
 * Component representing the import success or failure message.
 *
 * @param {boolean}  importSuccess - Flag indicating whether the import was successful.
 * @param {Function} handleReset   - Function to handle the reset action.
 */
const Success = ({ importSuccess, handleReset }) => {
	if (importSuccess) {
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
						<h3>All Done. Have fun!</h3>
					</div>
					<div className="ant-result-extra edi-d-flex edi-justify-content-center">
						<Button key="view-site" type="primary">
							<span>View Site</span>
							<ExportOutlined />
						</Button>
						<Button key="close" onClick={handleReset}>
							<CloseOutlined />
							<span>Close</span>
						</Button>
					</div>
				</div>
			</>
		);
	}

	return (
		<Result
			status="error"
			title="Something went wrong with the import"
			extra={[
				<Button
					key="retry"
					type="primary"
					onClick={() => window.location.reload()}
				>
					<RedoOutlined />
					<span>Reload & Retry</span>
				</Button>,
				<Button key="close" onClick={handleReset}>
					<CloseOutlined />
					<span>Close</span>
				</Button>,
			]}
		/>
	);
};

export default Success;
