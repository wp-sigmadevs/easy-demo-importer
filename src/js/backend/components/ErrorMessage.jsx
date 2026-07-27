import React from 'react';
import { Button, Col } from 'antd';

/**
 * Component for displaying an error message.
 *
 * The message may be a plain string (simple errors) or an object
 * `{ text, btnUrl?, btnText? }` for errors that carry a call-to-action button.
 * Both shapes are handled so a string message never renders blank.
 *
 * @param {Object}          props         - Component properties.
 * @param {(string|Object)} props.message - The error message: a string, or `{ text, btnUrl?, btnText? }`.
 */
const ErrorMessage = ({ message }) => {
	const isObject = message !== null && typeof message === 'object';
	const text = isObject ? message.text : message;
	const btnUrl = isObject ? message.btnUrl : undefined;
	const btnText = isObject ? message.btnText : undefined;

	const onProceed = () => {
		window.open(btnUrl, '_self');
	};

	return (
		<div>
			<Col>
				{message && (
					<div className="error-message">
						<span>{text}</span>

						{btnText && btnUrl && (
							<div className="btn_wrapper">
								<Button type="primary" onClick={onProceed}>
									<span>{btnText}</span>
								</Button>
							</div>
						)}
					</div>
				)}
			</Col>
		</div>
	);
};

export default ErrorMessage;
