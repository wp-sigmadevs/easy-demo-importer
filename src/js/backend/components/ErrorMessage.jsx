import React from 'react';
import { Button, Col } from 'antd';

/**
 * Component for displaying an error message.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.message - The error message to display.
 */
const ErrorMessage = ({ message }) => {
	const onProceed = () => {
		const btnTarget = message?.btnUrl;
		window.open(btnTarget, '_self');
	};

	return (
		<div>
			<Col>
				{message && (
					<>
						<div className="error-message">
							<span>{message?.text}</span>

							{message.btnText && message.btnUrl && (
								<div className="btn_wrapper">
									<Button type="primary" onClick={onProceed}>
										<span>{message?.btnText}</span>
									</Button>
								</div>
							)}
						</div>
					</>
				)}
			</Col>
		</div>
	);
};

export default ErrorMessage;
