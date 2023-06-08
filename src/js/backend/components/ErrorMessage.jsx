import React from 'react';
import { Col } from 'antd';

/**
 * Component to display an error message.
 *
 * @param {string} message - The error message to display.
 */
const ErrorMessage = ({ message }) => {
	return (
		<div>
			<Col>
				{message && <div className="error-message">{message}</div>}
			</Col>
		</div>
	);
};

export default ErrorMessage;
