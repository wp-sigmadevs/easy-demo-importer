import { Button, Space } from 'antd';
import { useNavigate } from 'react-router-dom';
import {
	ThunderboltOutlined, SafetyOutlined,
	FileSearchOutlined, CheckCircleOutlined,
} from '@ant-design/icons';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const FEATURES = [
	{ icon: <ThunderboltOutlined />, label: 'Chunked streaming XML import — no timeouts' },
	{ icon: <FileSearchOutlined />,  label: 'Live activity log during import' },
	{ icon: <SafetyOutlined />,      label: 'Session-based resumable imports' },
	{ icon: <CheckCircleOutlined />, label: 'Auto cache flush when done' },
];

const WelcomeStep = () => {
	const navigate = useNavigate();

	const footer = document.getElementById( 'edi-wizard-next-slot' );

	return (
		<>
			<div style={ { maxWidth: 540 } }>
				<h2 style={ { fontSize: 22, fontWeight: 700, marginBottom: 8 } }>
					Welcome to the Demo Importer
				</h2>
				<p style={ { color: '#595959', marginBottom: 28, fontSize: 15 } }>
					Import a complete demo in a few guided steps.
					The process takes roughly <strong>2–5 minutes</strong> depending on server speed and demo size.
				</p>

				<Space direction="vertical" size={ 12 } style={ { width: '100%' } }>
					{ FEATURES.map( ( f, i ) => (
						<div key={ i } style={ {
							display: 'flex', alignItems: 'center', gap: 10,
							padding: '10px 14px', background: '#f9fafb',
							borderRadius: 8, border: '1px solid #f0f0f0',
						} }>
							<span style={ { color: '#6366f1', fontSize: 16 } }>{ f.icon }</span>
							<span style={ { fontSize: 14, color: '#262626' } }>{ f.label }</span>
						</div>
					) ) }
				</Space>
			</div>

			{ footer && ReactDOM.createPortal(
				<Button
					type="primary"
					size="large"
					onClick={ () => navigate( '/wizard/requirements' ) }
					icon={ <ThunderboltOutlined /> }
				>
					Get Started
				</Button>,
				footer
			) }
		</>
	);
};

export default WelcomeStep;
