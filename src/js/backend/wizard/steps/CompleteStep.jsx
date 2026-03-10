import { Button, Result, Space } from 'antd';
import {
	EyeOutlined, SettingOutlined, BookOutlined, ReloadOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';

/* global sdEdiAdminParams */

const CompleteStep = () => {
	const navigate = useNavigate();
	const { resetWizard }    = useWizard();
	const { activeSessionId, resetStore } = useSharedDataStore();

	const handleImportAnother = () => {
		resetWizard();
		resetStore();
		navigate( '/wizard/welcome' );
	};

	return (
		<div>
			<Result
				status="success"
				title="Import Complete!"
				subTitle="Your demo content has been imported successfully. Caches have been flushed."
				extra={
					<Space wrap>
						<Button
							type="primary"
							icon={ <EyeOutlined /> }
							href={ sdEdiAdminParams.siteUrl }
							target="_blank"
						>
							View Site
						</Button>
						<Button
							icon={ <SettingOutlined /> }
							href={ sdEdiAdminParams.customizeUrl }
							target="_blank"
						>
							Customize
						</Button>
						<Button
							icon={ <BookOutlined /> }
							href="https://docs.sigmadevs.com/easy-demo-importer"
							target="_blank"
						>
							Documentation
						</Button>
						<Button
							icon={ <ReloadOutlined /> }
							onClick={ handleImportAnother }
						>
							Import Another Demo
						</Button>
					</Space>
				}
				style={ { marginBottom: 24 } }
			/>

			{ activeSessionId && (
				<ActivityFeed sessionId={ activeSessionId } active={ false } />
			) }
		</div>
	);
};

export default CompleteStep;
