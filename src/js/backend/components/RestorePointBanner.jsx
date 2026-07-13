import React, { useState, useEffect } from 'react';
import { Alert, Button, Modal } from 'antd';
import { RollbackOutlined, DeleteOutlined } from '@ant-design/icons';
import { Api } from '../utils/Api';

/* global sdEdiAdminParams */

/**
 * Persistent notice on the importer page whenever a pre-import restore point
 * exists, so a rollback is reachable outside the one-time result screen.
 *
 * @return {JSX.Element|null} The banner, or null when no restore point exists.
 */
const RestorePointBanner = () => {
	const [available, setAvailable] = useState(false);
	const [rolling, setRolling] = useState(false);
	const [discarding, setDiscarding] = useState(false);

	useEffect(() => {
		let ignore = false;

		Api.get('/sd/edi/v1/failed-media', { params: { session_id: '' } })
			.then((res) => {
				if (!ignore && res?.data?.success) {
					setAvailable(!!res.data.data.rollbackAvailable);
				}
			})
			.catch(() => {});

		return () => {
			ignore = true;
		};
	}, []);

	if (!available) {
		return null;
	}

	const handleRollback = () => {
		Modal.confirm({
			title: sdEdiAdminParams.rollbackTitle || 'Roll back this import?',
			content:
				sdEdiAdminParams.rollbackWarning ||
				'This restores your site to the moment before the import. Anything created since (new posts, orders, users) will be permanently lost.',
			okText: sdEdiAdminParams.rollbackConfirm || 'Roll back',
			okButtonProps: { danger: true },
			cancelText: sdEdiAdminParams.confirmNo || 'Cancel',
			centered: true,
			onOk: () => {
				setRolling(true);

				return Api.post('/sd/edi/v1/rollback', {})
					.then((res) => {
						if (res?.data?.success) {
							window.location.reload();
						} else {
							setRolling(false);
						}
					})
					.catch(() => setRolling(false));
			},
		});
	};

	/**
	 * Discards the restore point to reclaim its disk. Non-destructive to the site,
	 * but removes rollback — so it confirms first, then hides the banner.
	 */
	const handleDiscard = () => {
		Modal.confirm({
			title:
				sdEdiAdminParams.discardConfirmTitle ||
				'Discard the restore point?',
			content:
				sdEdiAdminParams.discardConfirmText ||
				'This frees the disk space used by the backup. You will no longer be able to roll this import back.',
			okText: sdEdiAdminParams.discardConfirm || 'Discard',
			cancelText: sdEdiAdminParams.confirmNo || 'Cancel',
			centered: true,
			onOk: () => {
				setDiscarding(true);

				return Api.post('/sd/edi/v1/discard-restore-point', {})
					.then((res) => {
						if (res?.data?.success) {
							setAvailable(false);
						} else {
							setDiscarding(false);
						}
					})
					.catch(() => setDiscarding(false));
			},
		});
	};

	return (
		<Alert
			type="warning"
			showIcon
			style={{ marginBottom: 20 }}
			message={
				sdEdiAdminParams.rollbackBannerTitle ||
				'A restore point from your last import is available.'
			}
			description={
				sdEdiAdminParams.rollbackBannerDesc ||
				'You can roll your site back to the state it was in before that import. Rolling back also removes anything created since.'
			}
			action={
				<>
					<Button
						loading={discarding}
						disabled={rolling}
						onClick={handleDiscard}
						style={{ marginRight: 8 }}
					>
						<DeleteOutlined />
						<span>
							{sdEdiAdminParams.discardButton ||
								'Discard restore point'}
						</span>
					</Button>
					<Button
						danger
						loading={rolling}
						disabled={discarding}
						onClick={handleRollback}
					>
						<RollbackOutlined />
						<span>
							{sdEdiAdminParams.rollbackButton || 'Roll Back'}
						</span>
					</Button>
				</>
			}
		/>
	);
};

export default RestorePointBanner;
