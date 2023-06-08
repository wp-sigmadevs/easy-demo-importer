import React from 'react';
import { Button, Timeline } from 'antd';
import { ProgressMessage } from '../../ProgressMessage';

const Imports = ({
	importStatus,
	importProgress,
	showImportProgress,
	handleImport,
}) => {
	const renderImportProgress = () => {
		return (
			<Timeline
				items={importProgress.map((progress, index) => ({
					children: (
						<ProgressMessage
							key={index}
							message={progress.message}
							fade={progress.fade}
						/>
					),
					key: index.toString(),
					className:
						index === importProgress.length - 1 ? 'active' : '',
				}))}
			/>
		);
	};

	return (
		<div className={`import-progress ${importStatus}`}>
			{showImportProgress ? (
				renderImportProgress()
			) : (
				<Button type="primary" onClick={handleImport}>
					Import
				</Button>
			)}
		</div>
	);
};

export default Imports;
