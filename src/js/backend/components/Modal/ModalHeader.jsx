import React from 'react';

/**
 * Component representing the header of a modal.
 *
 * @param {number} currentStep - The current step of the modal.
 * @param {string} title       - The title of the modal.
 */
const ModalHeader = ({ currentStep, title }) => {
	return (
		<div className="modal-header">
			<h3>{title}</h3>
			<div className="step-indicator">
				{[1, 2, 3].map((step) => (
					<div
						key={step}
						className={`step-dot ${
							step === currentStep ? 'active' : ''
						}`}
					/>
				))}
			</div>
		</div>
	);
};

export default ModalHeader;
