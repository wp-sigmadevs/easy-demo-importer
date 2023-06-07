import React from 'react';
import { Col } from 'antd';

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
