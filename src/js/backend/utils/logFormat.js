/* global sdEdiAdminParams */

// Runs the thumbnail tool records under this slug, shown distinctly from imports.
export const REGEN_SLUG = 'thumbnail-regeneration';
export const MANUAL_SLUG = '__manual__';

/**
 * Turns a raw demo slug ("home-01") into a display label ("Home-1"):
 * capitalizes the first letter and drops leading zeros from numeric segments.
 * Display only — the raw slug is still what's sent to the server/grouped by.
 *
 * @param {string} slug - Raw demo slug.
 * @return {string} Humanized label.
 */
export const humanizeSlug = (slug) => {
	if (!slug) {
		return slug;
	}

	return slug
		.replace(/(^|-)0*(\d+)/g, (_match, sep, digits) => `${sep}${digits}`)
		.replace(/^./, (c) => c.toUpperCase());
};

/**
 * Resolves a run's display name — the humanized demo slug, or the dedicated
 * label for the thumbnail-regeneration and manual-import pseudo-slugs.
 *
 * @param {Object} run - The run record.
 * @return {string} Display name.
 */
export const runName = (run) => {
	if (run.demo_slug === REGEN_SLUG) {
		return sdEdiAdminParams.logRegenLabel || 'Thumbnail Regeneration';
	}

	if (run.demo_slug === MANUAL_SLUG) {
		return sdEdiAdminParams.logManualLabel || 'Manual Import';
	}

	return (
		humanizeSlug(run.demo_slug) ||
		sdEdiAdminParams.logUnknownDemo ||
		'Import'
	);
};

/**
 * Human-readable status text for a run.
 *
 * @param {Object} run - The run record.
 * @return {string} Status label.
 */
export const runStatusText = (run) => {
	const statusText = {
		success: sdEdiAdminParams.logSuccess || 'Success',
		warning: sdEdiAdminParams.logWarning || 'Completed with warnings',
		error: sdEdiAdminParams.logFailed || 'Failed',
		info: sdEdiAdminParams.logInProgress || 'In progress',
		interrupted: sdEdiAdminParams.logInterrupted || 'Interrupted',
	};

	return statusText[run.status] || run.status;
};

/**
 * Parses a "YYYY-MM-DD HH:MM:SS" (UTC) stamp into a Date. Returns null for an
 * unexpected format rather than an Invalid Date, so callers can fall back to
 * the raw string.
 *
 * @param {string} stamp - Stored timestamp.
 * @return {?Date} Parsed date, or null.
 */
export const parseStamp = (stamp) => {
	if (typeof stamp !== 'string') {
		return null;
	}

	const iso = stamp.includes('T') ? stamp : `${stamp.replace(' ', 'T')}Z`;
	const date = new Date(iso);

	return Number.isNaN(date.getTime()) ? null : date;
};

/**
 * Human-readable run start, e.g. "Jul 11, 2026, 10:56 AM". Falls back to the
 * raw stamp if it can't be parsed.
 *
 * @param {string} stamp - Run's started_at stamp.
 * @return {string} Formatted date/time.
 */
export const formatRunTime = (stamp) => {
	const date = parseStamp(stamp);

	if (!date) {
		return stamp;
	}

	return date.toLocaleString(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	});
};

/**
 * Human-readable entry time, e.g. "10:56:01 AM". The run header already
 * carries the date, so entry rows stay to the clock only.
 *
 * @param {string} stamp - Entry's logged_at stamp.
 * @return {string} Formatted time.
 */
export const formatEntryTime = (stamp) => {
	const date = parseStamp(stamp);

	if (!date) {
		return stamp;
	}

	return date.toLocaleTimeString(undefined, {
		hour: 'numeric',
		minute: '2-digit',
		second: '2-digit',
	});
};

/**
 * Elapsed run time as a compact label, e.g. "45s", "1m 12s", "1h 3m". Measured
 * from the run's first entry (started_at) to its last entry's logged_at — the
 * log carries no explicit finish stamp, so the final entry is the finish. In
 * progress runs (status "info") have no meaningful end yet, so returns null;
 * also returns null when either stamp is unparseable or the span is negative.
 *
 * @param {Object} run - The run record.
 * @return {?string} Formatted duration, or null when not applicable.
 */
export const formatDuration = (run) => {
	if (run.status === 'info' || !run.entries || !run.entries.length) {
		return null;
	}

	const start = parseStamp(run.started_at);
	const end = parseStamp(run.entries[run.entries.length - 1].logged_at);

	if (!start || !end) {
		return null;
	}

	const seconds = Math.round((end.getTime() - start.getTime()) / 1000);

	if (seconds < 0) {
		return null;
	}

	if (seconds < 60) {
		return `${seconds}s`;
	}

	const minutes = Math.floor(seconds / 60);
	const remSeconds = seconds % 60;

	if (minutes < 60) {
		return remSeconds ? `${minutes}m ${remSeconds}s` : `${minutes}m`;
	}

	const hours = Math.floor(minutes / 60);
	const remMinutes = minutes % 60;

	return remMinutes ? `${hours}h ${remMinutes}m` : `${hours}h`;
};
