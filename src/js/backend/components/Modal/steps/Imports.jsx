import React from 'react';
import { Button, Timeline } from 'antd';
import { ProgressMessage } from '../../ProgressMessage';
import { ImportBar } from '../../ImportBar';

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
	 * Renders the import progress timeline. The progress bar is attached to the
	 * active card, but only for phases that report real progress (content import
	 * and image regeneration, flagged via progress.showBar) — every other phase
	 * shows a plain card.
	 */
	const renderImportProgress = () => {
		const activeIndex = importProgress.length - 1;

		return (
			<Timeline
				items={importProgress.map((progress, index) => ({
					children: (
						<>
							<ProgressMessage
								key={index}
								message={progress.message}
								fade={progress.fade}
							/>
							{index === activeIndex && progress.showBar && (
								<ImportBar percent={importPercent} />
							)}
						</>
					),
					key: index.toString(),
					className: index === activeIndex ? 'active' : '',
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
					{sdEdiAdminParams.btnImport}
				</Button>
			)}
		</div>
	);
};

export default Imports;
