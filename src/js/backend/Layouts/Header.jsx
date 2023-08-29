import React from 'react';

/**
 * Component representing the header layout of the demo importer.
 *
 * @param {Object} props         - Component properties.
 * @param {string} props.logo    - The URL of the EDI logo.
 * @param {string} props.heading - The heading to display.
 */
const Header = ({ logo, heading }) => {
	return (
		<div className="edi-header">
			<img src={logo} alt="EDI Logo" />
			<h1>{heading}</h1>
		</div>
	);
};

export default Header;
