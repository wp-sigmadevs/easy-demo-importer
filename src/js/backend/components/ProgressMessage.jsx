import React from 'react';

/**
 * Component for displaying a progress message.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.message - The message to display.
 */
export const ProgressMessage = ({ message }) => (
	<div className="progress-message">{message}</div>
);
