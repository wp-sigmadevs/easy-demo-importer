import React from 'react';
import { decodeEntities } from '../utils/decodeEntities';

/**
 * Component for displaying a progress message.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.message - The message to display.
 */
export const ProgressMessage = ({ message }) => (
	<div className="progress-message">{decodeEntities(message)}</div>
);
