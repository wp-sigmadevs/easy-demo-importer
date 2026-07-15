import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Button, Modal } from 'antd';
import {
	RollbackOutlined,
	DeleteOutlined,
	HistoryOutlined,
} from '@ant-design/icons';
import { Api } from '../utils/Api';
import useSharedDataStore from '../utils/sharedDataStore';

/* global sdEdiAdminParams */

/**
 * Persistent notice on the importer page whenever a pre-import restore point
 * exists, so a rollback is reachable outside the one-time result screen.
 *
 * @param {Object} props            - Component props.
 * @param {number} props.refreshKey - Bumped by the parent when a modal closes,
 *                                    so a just-created restore point shows
 *                                    without a page reload.
 * @return {JSX.Element|null} The banner, or null when no restore point exists.
 */
const RestorePointBanner = ({ refreshKey = 0 }) => {
	const [available, setAvailable] = useState(false);
	const [rolling, setRolling] = useState(false);
	const [discarding, setDiscarding] = useState(false);

	// The wizard modal's visibility lives in the shared store; re-check the
	// restore point when it closes so a snapshot created during that import
	// surfaces immediately. The manual modal is covered by refreshKey.
	const modalVisible = useSharedDataStore((state) => state.modalVisible);

	const mounted = useRef(true);
	const prevModalVisible = useRef(modalVisible);

	const refresh = useCallback(() => {
		Api.get('/sd/edi/v1/failed-media', { params: { session_id: '' } })
			.then((res) => {
				if (mounted.current && res?.data?.success) {
					setAvailable(!!res.data.data.rollbackAvailable);
				}
			})
			.catch(() => {});
	}, []);

	useEffect(() => {
		mounted.current = true;

		return () => {
			mounted.current = false;
		};
	}, []);

	// Initial load, and again each time the parent bumps refreshKey (a modal
	// just closed).
	useEffect(() => {
		refresh();
	}, [refresh, refreshKey]);

	// Re-check when the wizard modal transitions from open to closed.
	useEffect(() => {
		if (prevModalVisible.current && !modalVisible) {
			refresh();
		}

		prevModalVisible.current = modalVisible;
	}, [modalVisible, refresh]);

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
			className: 'confirmation-modal',
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
			className: 'confirmation-modal',
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
		<div className="edi-restore-banner">
			<div className="edi-restore-banner-info">
				<span className="edi-restore-banner-icon">
					<HistoryOutlined />
				</span>
				<div className="edi-restore-banner-text">
					<h4>
						{sdEdiAdminParams.rollbackBannerTitle ||
							'A restore point from your last import is available.'}
					</h4>
					<p>
						{sdEdiAdminParams.rollbackBannerDesc ||
							'You can roll your site back to the state it was in before that import. Rolling back also removes anything created since.'}
					</p>
				</div>
			</div>
			<div className="edi-restore-banner-actions">
				<Button
					className="edi-restore-discard"
					loading={discarding}
					disabled={rolling}
					onClick={handleDiscard}
					icon={<DeleteOutlined />}
				>
					{sdEdiAdminParams.discardButton || 'Discard Backup'}
				</Button>
				<Button
					className="edi-restore-rollback"
					type="primary"
					danger
					loading={rolling}
					disabled={discarding}
					onClick={handleRollback}
					icon={<RollbackOutlined />}
				>
					{sdEdiAdminParams.rollbackButton || 'Roll Back'}
				</Button>
			</div>
		</div>
	);
};

export default RestorePointBanner;
