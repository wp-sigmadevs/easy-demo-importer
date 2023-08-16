import React from 'react';

/**
 * The header layout of the demo importer.
 *
 * @param {string} logo    - The URL of the EDI logo.
 * @param {string} heading - The Heading.
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
