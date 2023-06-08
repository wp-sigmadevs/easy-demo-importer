import React from 'react';

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
