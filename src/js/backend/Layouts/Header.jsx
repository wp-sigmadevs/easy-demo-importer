import React from 'react';

const Header = ({ logo }) => {
	return (
		<div className="edi-header">
			<img src={logo} alt="EDI Logo" />
			<h1>Demo Importer</h1>
		</div>
	);
};

export default Header;
