import React from 'react';

/**
 * Component representing the header of a modal.
 *
 * @param {Object} props             - Component properties.
 * @param {number} props.currentStep - The current step of the modal.
 */
const ModalHeader = ({ currentStep }) => {
	return (
		<div className="modal-header">
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
