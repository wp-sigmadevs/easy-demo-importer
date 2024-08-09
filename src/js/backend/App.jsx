import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import AppDemoImporter from './AppDemoImporter';
import AppServer from './AppServer';

const App = () => {
	return (
		<HashRouter>
			<Routes>
				<Route path="/" element={<AppDemoImporter />} />
				<Route path="/system_status_page" element={<AppServer />} />
				<Route path="*" element={<Navigate to="/" replace />} />
			</Routes>
		</HashRouter>
	);
};

export default App;
