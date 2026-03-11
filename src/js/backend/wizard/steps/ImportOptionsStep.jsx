import { Button, Switch, Alert } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useWizard } from '../WizardContext';
import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';

const OPTION_GROUPS = [
	{
		label: 'Content',
		options: [
			{ key: 'content',        label: 'Posts & Pages',         desc: 'All WXR content items' },
			{ key: 'media',          label: 'Media & Images',        desc: 'Download remote attachments' },
			{ key: 'menus',          label: 'Navigation Menus',      desc: 'Menus and menu item assignments' },
		],
	},
	{
		label: 'Settings',
		options: [
			{ key: 'customizer',     label: 'Customizer Settings',   desc: 'Colors, fonts, layout settings' },
			{ key: 'widgets',        label: 'Widgets',               desc: 'Sidebar and footer widgets' },
			{ key: 'pluginSettings', label: 'Plugin Settings',       desc: 'Theme options and plugin config' },
		],
	},
	{
		label: 'After Import',
		options: [
			{ key: 'regenImages', label: 'Regenerate Image Thumbnails',
			  desc: 'Rebuild all image sizes after import completes. Disable to skip (faster, but thumbnails may be missing).' },
		],
	},
	{
		label: 'Database',
		options: [
			{ key: 'resetDb',        label: 'Reset Database Before Import',
			  desc: 'Deletes existing posts, terms, and options. Use on a fresh install.', danger: true },
		],
	},
];

const ImportOptionsStep = () => {
	const navigate = useNavigate();
	const { importOptions, updateOption } = useWizard();
	const [footer, setFooter] = useState(null);
	const [back, setBack] = useState(null);

	useEffect(() => {
		const nextEl = document.getElementById('edi-wizard-next-slot');
		const backEl = document.getElementById('edi-wizard-back-slot');
		if (nextEl) setFooter(nextEl);
		if (backEl) setBack(backEl);
	}, []);

	return (
		<>
			<h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Import Options</h2>
			<p style={ { color: '#8c8c8c', marginBottom: 24, fontSize: 14 } }>
				Choose what to include in the import. All options are enabled by default.
			</p>

			{ OPTION_GROUPS.map( ( group ) => (
				<div key={ group.label } style={ { marginBottom: 24 } }>
					<div style={ { fontSize: 12, fontWeight: 700, color: '#8c8c8c', textTransform: 'uppercase', letterSpacing: '0.6px', marginBottom: 10 } }>
						{ group.label }
					</div>
					<div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
						{ group.options.map( ( opt ) => (
							<div key={ opt.key } style={ {
								display: 'flex', justifyContent: 'space-between', alignItems: 'center',
								padding: '12px 14px', background: '#fafafa',
								borderRadius: 8, border: `1px solid ${ opt.danger && importOptions[ opt.key ] ? '#ffa39e' : '#f0f0f0' }`,
							} }>
								<div>
									<div style={ { fontSize: 14, fontWeight: 500, color: opt.danger ? '#cf1322' : '#262626' } }>
										{ opt.label }
									</div>
									<div style={ { fontSize: 12, color: '#8c8c8c' } }>{ opt.desc }</div>
								</div>
								<Switch
									checked={ importOptions[ opt.key ] }
									onChange={ ( v ) => updateOption( opt.key, v ) }
								/>
							</div>
						) ) }
					</div>
				</div>
			) ) }

			{ importOptions.resetDb && (
				<Alert
					type="warning"
					showIcon
					message="Database reset is enabled. All existing posts, pages, and settings will be permanently deleted before import."
					style={ { marginTop: 8 } }
				/>
			) }

			{ back && ReactDOM.createPortal(
				<Button onClick={ () => navigate( '/wizard/demos' ) }>Back</Button>,
				back
			) }

			{ footer && ReactDOM.createPortal(
				<Button type="primary" onClick={ () => navigate( '/wizard/confirm' ) }>
					Review & Confirm
				</Button>,
				footer
			) }
		</>
	);
};

export default ImportOptionsStep;
