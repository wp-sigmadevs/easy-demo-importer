import React from 'react';

/**
 * The header layout of the demo importer.
 *
 * @param {Object} props      - The component props.
 * @param {string} props.logo - The URL of the EDI logo.
 */
const Header = ({ logo }) => {
	return (
		<div className="edi-header">
			<img src={logo} alt="EDI Logo" />
			<h1>Demo Importer</h1>
		</div>
	);
};

export default Header;
