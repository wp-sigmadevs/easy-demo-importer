import { useState } from 'react';
import { Button, Result, Space, Modal } from 'antd';
import {
	EyeOutlined, SettingOutlined, BookOutlined, ReloadOutlined, RollbackOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';
import { Api } from '../../utils/Api';

/* global sdEdiAdminParams */

const CompleteStep = () => {
	const navigate = useNavigate();
	const { resetWizard, snapshotId } = useWizard();
	const { activeSessionId, resetStore } = useSharedDataStore();

	const [ rolling,   setRolling  ] = useState( false );
	const [ undone,    setUndone   ] = useState( false );
	const [ rollError, setRollError ] = useState( null );

	const handleImportAnother = () => {
		resetWizard();
		resetStore();
		navigate( '/wizard/welcome' );
	};

	const handleUndo = () => {
		Modal.confirm( {
			title:      'Undo this import?',
			content:    'This will delete all posts, pages, and media imported during this session and restore your previous settings. This cannot be undone.',
			okText:     'Yes, undo import',
			okType:     'danger',
			cancelText: 'Cancel',
			onOk: async () => {
				setRolling( true );
				try {
					await Api.post( `/sd/edi/v1/rollback/${ snapshotId }`, {} );
					setUndone( true );
				} catch ( err ) {
					setRollError( err?.response?.data?.message || 'Rollback failed. Please try again.' );
				} finally {
					setRolling( false );
				}
			},
		} );
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
						{ snapshotId && ! undone && (
							<Button
								icon={ <RollbackOutlined /> }
								danger
								onClick={ handleUndo }
								disabled={ rolling }
							>
								{ rolling ? 'Undoing…' : 'Undo Import' }
							</Button>
						) }
						{ undone && (
							<Button disabled icon={ <RollbackOutlined /> }>Import Undone</Button>
						) }
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

			{ rollError && (
				<div style={ { color: '#cf1322', marginTop: 8, textAlign: 'center' } }>{ rollError }</div>
			) }

			{ activeSessionId && (
				<ActivityFeed sessionId={ activeSessionId } active={ false } />
			) }
		</div>
	);
};

export default CompleteStep;
