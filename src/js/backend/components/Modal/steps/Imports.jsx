import React from 'react';
import { Button, Timeline, Progress } from 'antd';
import { ProgressMessage } from '../../ProgressMessage';

/* global sdEdiAdminParams */

/**
 * Component representing the imports step in the modal.
 *
 * @param {string}   importStatus       - The import status.
 * @param {Array}    importProgress     - The progress of the import.
 * @param {?number}  importPercent      - Determinate content-import progress (0-100), or null to hide the bar.
 * @param {boolean}  showImportProgress - Flag indicating whether to show the import progress.
 * @param {Function} handleImport       - Function to handle the import process.
 */
const Imports = ({
	importStatus,
	importProgress,
	importPercent = null,
	showImportProgress,
	handleImport,
}) => {
	/**
	 * Renders the import progress timeline.
	 */
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
				<>
					{importPercent !== null && (
						<Progress
							percent={importPercent}
							status="active"
							className="sd-edi-import-bar"
						/>
					)}
					{renderImportProgress()}
				</>
			) : (
				<Button type="primary" onClick={handleImport}>
					{sdEdiAdminParams.btnImport}
				</Button>
			)}
		</div>
	);
};

export default Imports;
