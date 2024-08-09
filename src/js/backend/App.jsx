import {
	HashRouter,
	Navigate,
	Route,
	Routes,
	useLocation,
} from 'react-router-dom';
import { useEffect } from 'react';
import AppDemoImporter from './AppDemoImporter';
import AppServer from './AppServer';

const App = () => {
	return (
		<HashRouter>
			<AppContent />
		</HashRouter>
	);
};

const AppContent = () => {
	const location = useLocation();

	useEffect(() => {
		// Smooth scroll to top when the location changes
		window.scrollTo({ top: 0, behavior: 'smooth' });

		const updateMenuLink = () => {
			const demoImporterLink = document.querySelector(
				"a[href*='themes.php?page=sd-easy-demo-importer']"
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
					parentLi.classList.remove('current');
					parentLi.classList.remove('current_page_item');
				}
			});

			// Check for the active hash
			if (activeHash.includes('system_status_page')) {
				const systemStatusMenu = document.querySelector(
					"a[href*='themes.php?page=sd-easy-demo-importer#/system_status_page']"
				);
				if (systemStatusMenu) {
					systemStatusMenu.closest('li').classList.add('current');
					systemStatusMenu
						.closest('li')
						.classList.add('current_page_item');
				}
			} else if (demoImporterLink) {
				demoImporterLink.closest('li').classList.add('current');
				demoImporterLink
					.closest('li')
					.classList.add('current_page_item');
			}
		};

		updateMenuLink();

		// Listen for hash changes
		window.addEventListener('hashchange', updateMenuLink);

		return () => {
			window.removeEventListener('hashchange', updateMenuLink);
		};
	}, [location]);

	return (
		<Routes>
			<Route path="/" element={<AppDemoImporter />} />
			<Route path="/system_status_page" element={<AppServer />} />
			<Route path="*" element={<Navigate to="/" replace />} />
		</Routes>
	);
};

export default App;
