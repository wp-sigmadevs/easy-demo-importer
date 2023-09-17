/**
 * Demo Import Page
 */

import App from './backend/App';
import ReactDOM from 'react-dom/client';
import AppServer from './backend/AppServer';

/**
 * The container element where the pages will be rendered.
 *
 * @type {HTMLElement}
 */
const container = document.getElementById('sd-edi-demo-import-container');
const serverContainer = document.getElementById(
	'sd-edi-server-status-container'
);

if (container) {
	/**
	 * The root element for rendering the demo import page.
	 *
	 * @type {ReactDOM.Root}
	 */
	const root = ReactDOM.createRoot(container);

	/**
	 * Render the demo import page.
	 */
	root.render(<App />);
}

if (serverContainer) {
	/**
	 * The root element for rendering the server status page.
	 *
	 * @type {ReactDOM.Root}
	 */
	const serverRoot = ReactDOM.createRoot(serverContainer);

	/**
	 * Render the server status page.
	 */
	serverRoot.render(<AppServer />);
}
