import { Button, Image, Space } from 'antd';
import { DownloadOutlined, FullscreenOutlined } from '@ant-design/icons';
import React from 'react';

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
								<span>Click to Enlarge</span>
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
					Preview
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
						<span>Import</span> <DownloadOutlined />
					</Button>
				</div>
			</div>
		</div>
	);
};

export default DemoCard;
