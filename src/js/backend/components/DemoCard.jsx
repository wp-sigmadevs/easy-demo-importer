import React, { useState } from 'react';
import { Button, Space, Skeleton, Image, Tooltip } from 'antd';
import {
	DownloadOutlined,
	FullscreenOutlined,
	LockOutlined,
} from '@ant-design/icons';

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

	// Server-computed: false when the demo's `requires` block is unmet.
	// Legacy configs omit the field, so treat undefined as met.
	const missing = data?.missingRequirements ?? [];
	const requirementsMet = data?.requirementsMet !== false;

	const importButton = (
		<Button
			className="edi-modal-button"
			type="primary"
			disabled={!requirementsMet}
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
			{requirementsMet ? <DownloadOutlined /> : <LockOutlined />}
		</Button>
	);

	return (
		<div
			className={
				requirementsMet ? 'demo-card' : 'demo-card requirements-unmet'
			}
		>
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
					{requirementsMet ? (
						importButton
					) : (
						<Tooltip
							title={
								<>
									<div>
										{sdEdiAdminParams.requirementsNotMet}
									</div>
									<ul className="edi-requirements-list">
										{missing.map((item) => (
											<li key={item}>{item}</li>
										))}
									</ul>
									<div>
										{
											sdEdiAdminParams.requirementsNotMetHint
										}
									</div>
								</>
							}
						>
							{/* span wrapper: Tooltip needs a hoverable node
							    while the button is disabled. */}
							<span className="edi-disabled-wrap">
								{importButton}
							</span>
						</Tooltip>
					)}
				</div>
			</div>
		</div>
	);
};

export default DemoCard;
