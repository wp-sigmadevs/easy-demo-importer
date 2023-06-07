import React, { useEffect, useState } from 'react';

export const ProgressMessage = ({ message }) => {
	const [showMessage, setShowMessage] = useState(false);
	const [isSuccess, setIsSuccess] = useState(false);

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
