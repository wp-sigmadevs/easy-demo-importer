import React from 'react';
import { Col } from 'antd';

/**
 * Component for displaying an error message.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.message - The error message to display.
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
