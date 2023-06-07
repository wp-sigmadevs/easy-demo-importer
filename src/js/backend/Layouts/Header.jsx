import React from 'react';
import { Image } from 'antd';

const Header = ({ logo }) => {
	return (
		<div className="edi-header">
			<Image src={logo} alt="EDI Logo" />
			<h1>Demo Importer</h1>
		</div>
	);
};

export default Header;
