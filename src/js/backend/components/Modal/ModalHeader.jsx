import React from 'react';

const ModalHeader = ({ currentStep }) => {
	return (
		<div className="modal-header">
			<h3>Step {currentStep}: Before You Proceed</h3>
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
