/**
 * Demo Import Page
 */

// Imports
import ReactDOM from 'react-dom/client';
import App from './components/app';
// import { StateProvider } from './utils/StateProvider';
// import reducer, { initialState } from './admin/utils/reducer';

// Container
const container = document.getElementById('sd-edi-demo-import-container');

// Root
const root = ReactDOM.createRoot(container);

// Render
root.render(
	// <StateProvider reducer={reducer} initialState={initialState}>
		<App />
	// </StateProvider>
);
