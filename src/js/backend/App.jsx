// App.jsx
import {
	createHashRouter,
	RouterProvider,
	Navigate,
	useLocation,
	useNavigationType,
} from 'react-router-dom';
import { useEffect } from 'react';

import AppDemoImporter from './AppDemoImporter';
import AppServer from './AppServer';

/* global sdEdiAdminParams */

// Component to handle scroll and menu logic
const LayoutWithEffects = ({ children }) => {
	const location = useLocation();
	const navType = useNavigationType(); // Optional: distinguish PUSH/REPLACE

	useEffect(() => {
		// Scroll to top on route change
		window.scrollTo({ top: 0, behavior: 'smooth' });

		const updateMenuLink = () => {
			const demoImporterLink = document.querySelector(
				`a[href*="${sdEdiAdminParams.importPageLink}"]`
			);

			if (demoImporterLink && !demoImporterLink.href.includes('#/')) {
				demoImporterLink.href += '#/';
			}

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

			if (activeHash.includes('system_status_page')) {
				const systemStatusMenu = document.querySelector(
					`a[href="${sdEdiAdminParams.serverPageUrl}"]`
				);
				if (systemStatusMenu) {
					systemStatusMenu
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
		window.addEventListener('hashchange', updateMenuLink);

		return () => {
			window.removeEventListener('hashchange', updateMenuLink);
		};
	}, [location]);

	return children;
};

// Routes with layout wrapper
const routes = [
	{
		path: '/',
		element: (
			<LayoutWithEffects>
				<AppDemoImporter />
			</LayoutWithEffects>
		),
	},
	{
		path: '/system_status_page',
		element: (
			<LayoutWithEffects>
				<AppServer />
			</LayoutWithEffects>
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
