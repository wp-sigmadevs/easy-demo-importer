import {
	createHashRouter,
	RouterProvider,
	Navigate,
	useLocation,
} from 'react-router-dom';
import { useEffect } from 'react';

import AppStatus from './AppStatus';
import AppRegenerate from './AppRegenerate';
import AppDemoImporter from './AppDemoImporter';
import ErrorBoundary from './components/ErrorBoundary';

/* global sdEdiAdminParams */

/**
 * Component to handle scroll and menu logic
 *
 * @param {JSX.Element} children - The wrapped content.
 * @return {JSX.Element} The wrapped content with scroll and menu logic.
 */
const LayoutWithEffects = ({ children }) => {
	const location = useLocation();

	useEffect(() => {
		// Scroll to top on route change
		window.scrollTo({ top: 0, behavior: 'smooth' });

		/**
		 * Updates the menu links and adds active class based on the current hash.
		 */
		const updateMenuLink = () => {
			const demoImporterLink = document.querySelector(
				`#adminmenu #menu-appearance .wp-submenu a[href*="${sdEdiAdminParams.importPageLink}"]`
			);

			if (demoImporterLink && !demoImporterLink.href.includes('#/')) {
				demoImporterLink.href += '#/';
			}

			// Add active class based on current hash
			const activeHash = window.location.hash;
			const menuItems = document.querySelectorAll(
				'#adminmenu #menu-appearance .wp-submenu a'
			);
			menuItems.forEach((item) => {
				const parentLi = item.closest('li');
				if (parentLi) {
					parentLi.classList.remove('current', 'current_page_item');
				}
			});

			// The status + log tabs live under a single admin menu item, so both
			// hashes highlight the System Status menu.
			if (
				activeHash.includes('system_status_page') ||
				activeHash.includes('import_log')
			) {
				const systemStatusMenu = document.querySelector(
					`a[href="${sdEdiAdminParams.serverPageUrl}"]`
				);
				if (systemStatusMenu) {
					systemStatusMenu
						.closest('li')
						.classList.add('current', 'current_page_item');
				}
			} else if (activeHash.includes('regenerate_thumbnails')) {
				const regenMenu = document.querySelector(
					`a[href="${sdEdiAdminParams.regenPageUrl}"]`
				);
				if (regenMenu) {
					regenMenu
						.closest('li')
						.classList.add('current', 'current_page_item');
				}
			} else if (demoImporterLink) {
				demoImporterLink
					.closest('li')
					.classList.add('current', 'current_page_item');
			}
		};

		updateMenuLink();

		// Listen for hash changes
		window.addEventListener('hashchange', updateMenuLink);

		return () => {
			window.removeEventListener('hashchange', updateMenuLink);
		};
	}, [location]);

	return children;
};

/**
 * Array of routes.
 *
 * @type {Array}
 */
const routes = [
	{
		path: '/',
		element: (
			<ErrorBoundary>
				<LayoutWithEffects>
					<AppDemoImporter />
				</LayoutWithEffects>
			</ErrorBoundary>
		),
	},
	{
		path: '/system_status_page',
		element: (
			<ErrorBoundary>
				<LayoutWithEffects>
					<AppStatus defaultTab="status" />
				</LayoutWithEffects>
			</ErrorBoundary>
		),
	},
	{
		path: '/import_log',
		element: (
			<ErrorBoundary>
				<LayoutWithEffects>
					<AppStatus defaultTab="log" />
				</LayoutWithEffects>
			</ErrorBoundary>
		),
	},
	{
		path: '/regenerate_thumbnails',
		element: (
			<ErrorBoundary>
				<LayoutWithEffects>
					<AppRegenerate />
				</LayoutWithEffects>
			</ErrorBoundary>
		),
	},
	{
		path: '*',
		element: <Navigate to="/" replace />,
	},
];

const router = createHashRouter(routes, {
	future: {
		v7_startTransition: true,
	},
});

/**
 * Main App component.
 * Wraps the content with HashRouter.
 *
 * @return {JSX.Element} App component
 */
const App = () => {
	return (
		<RouterProvider
			router={router}
			future={{
				v7_startTransition: true,
			}}
		/>
	);
};

export default App;
