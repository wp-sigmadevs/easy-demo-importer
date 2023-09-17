import React, { useEffect, useState } from 'react';

/**
 * Component for displaying a progress message.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.message - The message to display.
 */
export const ProgressMessage = ({ message }) => {
	/**
	 * State hooks
	 */
	const [showMessage, setShowMessage] = useState(false);
	const [isSuccess, setIsSuccess] = useState(false);

	/**
	 * Effect hook to show and animate the progress message.
	 */
	useEffect(() => {
		setTimeout(() => {
			setShowMessage(true);

			setTimeout(() => {
				setIsSuccess(true);
			}, 2000);
		}, 0);
	}, []);

	return (
		<div className={`progress-message ${isSuccess ? 'success' : ''}`}>
			<span
				className={`fade ${
					showMessage ? 'msg-fade-in' : 'msg-fade-out'
				}`}
			>
				{message}
			</span>
		</div>
	);
};
