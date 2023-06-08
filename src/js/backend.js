/**
 * Demo Import Page
 */

import App from './backend/App';
import ReactDOM from 'react-dom/client';

/**
 * The container element where the demo import page will be rendered.
 *
 * @type {HTMLElement}
 */
const container = document.getElementById('sd-edi-demo-import-container');

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
