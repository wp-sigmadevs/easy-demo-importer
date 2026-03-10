import { Button, Spin } from 'antd';
import { useNavigate } from 'react-router-dom';
import React, { useState, useEffect } from 'react';
import PluginList from '../../components/PluginList';
import useSharedDataStore from '../../utils/sharedDataStore';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const PluginInstallerStep = () => {
	const navigate = useNavigate();
	const { pluginList, fetchPluginList, loading, setLoading } = useSharedDataStore();
	const [footer, setFooter] = useState(null);
	const [back, setBack] = useState(null);

	useEffect( () => {
		setLoading( true );
		fetchPluginList( '/sd/edi/v1/plugin/list' );
	}, [] );

	useEffect(() => {
		const nextEl = document.getElementById('edi-wizard-next-slot');
		const backEl = document.getElementById('edi-wizard-back-slot');
		if (nextEl) setFooter(nextEl);
		if (backEl) setBack(backEl);
	}, []);

	const demoPluginData = pluginList.success ? pluginList.data : [];
	const pluginArray    = Object.entries( demoPluginData ).map( ( [ key, value ] ) => ( { key, ...value } ) );
	const allActive      = pluginArray.every( ( p ) => p.active );

	return (
		<>
			<h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Required Plugins</h2>
			<p style={ { color: '#8c8c8c', marginBottom: 20, fontSize: 14 } }>
				The following plugins are needed for this demo. Install and activate them before proceeding.
			</p>

			{ loading
				? <Spin size="large" style={ { display: 'block', margin: '40px auto' } } />
				: <PluginList plugins={ pluginArray } />
			}

			{ back && ReactDOM.createPortal(
				<Button onClick={ () => navigate( '/wizard/requirements' ) }>Back</Button>,
				back
			) }

			{ footer && ReactDOM.createPortal(
				<Button
					type="primary"
					disabled={ loading }
					onClick={ () => navigate( '/wizard/demos' ) }
				>
					{ allActive ? 'Next' : 'Skip for now' }
				</Button>,
				footer
			) }
		</>
	);
};

export default PluginInstallerStep;
