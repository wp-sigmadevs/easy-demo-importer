import React, { useState } from 'react';
import { Button, Space, Skeleton, Image } from 'antd';
import { DownloadOutlined, FullscreenOutlined } from '@ant-design/icons';

/* global sdEdiAdminParams */

/**
 * Component representing a demo card.
 *
 * @param {Object}   props           - Component properties.
 * @param {Object}   props.data      - The data object for the demo card.
 * @param {Function} props.showModal - Function to show the modal with the demo card details.
 */
const DemoCard = ({ data, showModal }) => {
	const [imageLoaded, setImageLoaded] = useState(false);

	const handleImageLoad = () => {
		setImageLoaded(true);
	};

	return (
		<div className="demo-card">
			<header>
				{imageLoaded ? (
					<>
						<Image
							src={data?.previewImage}
							preview={{
								maskClassName: 'custom-mask',
								mask: (
									<Space>
										<FullscreenOutlined />
										<span>
											{sdEdiAdminParams.clickEnlarge}
										</span>
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
					</>
				) : (
					<>
						<Skeleton.Node block={true} active={true} />
						<img
							src={data?.previewImage}
							alt="Preview"
							onLoad={handleImageLoad}
							style={{ display: 'none' }}
						/>
					</>
				)}
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
						<span>{sdEdiAdminParams.btnImport}</span>{' '}
						<DownloadOutlined />
					</Button>
				</div>
			</div>
		</div>
	);
};

export default DemoCard;
