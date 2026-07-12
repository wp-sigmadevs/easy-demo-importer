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
	 * Renders the import progress timeline. The progress bar is attached to
	 * cards for phases that report real progress (content import and image
	 * regeneration, flagged via progress.showBar) — every other phase shows a
	 * plain card. Once shown, a card's bar keeps rendering even after it's
	 * demoted from active (frozen at 100%, since demotion only happens once
	 * that phase has finished) rather than unmounting — unmounting it snapped
	 * the card's height down right as it eased into the 3D stack, reading as
	 * an unwanted grow/shrink before the card settled into place.
	 */
	const renderImportProgress = () => {
		const activeIndex = importProgress.length - 1;

		return (
			<Timeline
				items={importProgress.map((progress, index) => ({
					children: (
						<>
							<ProgressMessage message={progress.message} />
							{progress.showBar && (
								<ImportBar
									percent={
										index === activeIndex
											? importPercent
											: 100
									}
									active={index === activeIndex}
								/>
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
