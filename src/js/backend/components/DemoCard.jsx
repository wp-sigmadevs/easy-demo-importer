import React from 'react';
import { Button, Image, Space } from 'antd';
import { DownloadOutlined, FullscreenOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing a demo card.
 *
 * @param {Object}   data      - The data object for the demo card.
 * @param {Function} showModal - Function to show the modal with the demo card details.
 */
const DemoCard = ({ data, showModal }) => {
	return (
		<div className="demo-card">
			<header>
				<Image
					src={data?.previewImage}
					preview={{
						maskClassName: 'custom-mask',
						mask: (
							<Space>
								<FullscreenOutlined />
								<span>{sdEdiAdminParams.clickEnlarge}</span>
							</Space>
						),
					}}
					alt="Preview"
				/>
				<a
					className="details"
					target="_blank"
					href={data?.previewUrl}
					rel="noreferrer"
				>
					{sdEdiAdminParams.btnLivePreview}
				</a>
			</header>
			<div className="edi-demo-content">
				<div className="demo-name">
					<h2>{data?.name}</h2>
				</div>
				<div className="edi-demo-actions">
					<Button
						className="edi-modal-button"
						type="primary"
						onClick={() =>
							showModal({
								id: data.id,
								data,
								reset: true,
								excludeImages: true,
							})
						}
					>
						<span>{sdEdiAdminParams.btnImport}</span> <DownloadOutlined />
					</Button>
				</div>
			</div>
		</div>
	);
};

export default DemoCard;
