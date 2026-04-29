import React from 'react';

/* global sdEdiAdminParams */

/**
 * Sticky multisite banner.
 *
 * Renders a non-intrusive notice identifying which subsite the user is about
 * to modify. Returns null on single-site installs so the existing UI is
 * unaffected.
 */
const SubsiteBanner = () => {
	if (!sdEdiAdminParams || !sdEdiAdminParams.isMultisite) {
		return null;
	}

	return (
		<div
			className="sd-edi-subsite-banner"
			role="status"
			aria-live="polite"
		>
			<span className="sd-edi-subsite-banner__text">
				{sdEdiAdminParams.subsiteBannerLabel}
			</span>
			{sdEdiAdminParams.currentBlogUrl && (
				<a
					className="sd-edi-subsite-banner__link"
					href={sdEdiAdminParams.currentBlogUrl}
					target="_blank"
					rel="noreferrer"
				>
					{sdEdiAdminParams.currentBlogUrl}
				</a>
			)}
		</div>
	);
};

export default SubsiteBanner;
