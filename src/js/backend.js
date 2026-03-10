/**
 * Demo Import Page
 */

import App from './backend/App';
import ReactDOM from 'react-dom/client';
import { ConfigProvider } from 'antd';

const ediTheme = {
	token: {
		colorPrimary:   '#6366f1',
		colorSuccess:   '#10b981',
		colorWarning:   '#f59e0b',
		colorError:     '#ef4444',
		borderRadius:    10,
		borderRadiusLG:  14,
		fontFamily:     '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
		boxShadow:      '0 4px 24px rgba(0,0,0,0.08)',
	},
	components: {
		Button:   { borderRadius: 8, controlHeight: 40 },
		Switch:   { colorPrimary: '#6366f1' },
		Modal:    { borderRadiusLG: 16 },
		Progress: { colorInfo: '#6366f1' },
		Steps:    { colorPrimary: '#6366f1' },
		Tag:      { borderRadius: 6 },
	},
};

const container = document.getElementById( 'sd-edi-demo-import-container' );

if ( container ) {
	const root = ReactDOM.createRoot( container );
	root.render(
		<ConfigProvider theme={ ediTheme }>
			<App />
		</ConfigProvider>
	);
}
