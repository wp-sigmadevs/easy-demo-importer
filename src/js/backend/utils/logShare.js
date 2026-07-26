import { decodeEntities } from './decodeEntities';
import {
	formatDuration,
	formatEntryTime,
	formatRunTime,
	runName,
	runStatusText,
} from './logFormat';

/* global sdEdiAdminParams */

// Widest level token ("interrupted") — pad all levels to this so the message
// column stays aligned in the plain-text report.
const LEVEL_PAD = 11;

/**
 * Environment section, reusing the System Status report data. Skips the
 * "Copy System Data" tab (a rendered textarea, not real fields) and the
 * inactive-plugins list (noise for a support bundle). Returns '' when server
 * info is unavailable, so the report degrades to runs-only.
 *
 * @param {Object} serverInfo - The `serverData.data` map from the store.
 * @return {string} Formatted environment block, or ''.
 */
const environmentBlock = (serverInfo) => {
	if (!serverInfo || typeof serverInfo !== 'object') {
		return '';
	}

	const skip = ['copy_system_data', 'inactive_plugins'];
	let block = '';

	Object.keys(serverInfo).forEach((sectionKey) => {
		if (skip.includes(sectionKey)) {
			return;
		}

		const section = serverInfo[sectionKey];

		if (!section || !section.fields) {
			return;
		}

		block += `== ${section.label} ==\n`;

		Object.keys(section.fields).forEach((fieldKey) => {
			const field = section.fields[fieldKey];
			block += `\t${field.label}: ${field.value}\n`;
		});

		block += '\n';
	});

	return block;
};

/**
 * One run rendered as a header + timeline.
 *
 * @param {Object} run - The run record.
 * @return {string} Formatted run block.
 */
const runBlock = (run) => {
	const duration = formatDuration(run);
	const entries = run.entries || [];
	const finished = entries.length
		? entries[entries.length - 1].logged_at
		: run.started_at;

	const header = [runName(run), runStatusText(run), duration]
		.filter(Boolean)
		.join(' · ');

	let block = `--- ${header} ---\n`;
	block += `${sdEdiAdminParams.logShareStarted || 'Started'}: ${formatRunTime(
		run.started_at
	)}\n`;
	block += `${
		sdEdiAdminParams.logShareFinished || 'Finished'
	}: ${formatRunTime(finished)}\n`;
	block += `${sdEdiAdminParams.logShareEntries || 'Entries'}: ${
		run.count
	}\n\n`;

	entries.forEach((entry) => {
		const time = formatEntryTime(entry.logged_at);
		const level = String(entry.level || 'info')
			.toUpperCase()
			.padEnd(LEVEL_PAD);
		block += `[${time}] ${level} ${decodeEntities(entry.message)}\n`;
	});

	return block;
};

/**
 * Builds the plain-text support bundle: a title, the environment block (when
 * available), then one block per run.
 *
 * @param {Array}  runs       - Runs to include (one, or the full history).
 * @param {Object} serverInfo - The `serverData.data` map, or falsy to omit env.
 * @return {string} The full report text.
 */
export const buildReport = (runs, serverInfo) => {
	const title =
		sdEdiAdminParams.logShareTitle || 'Easy Demo Importer — Import Log';

	let report = `=== ${title} ===\n\n`;

	const env = environmentBlock(serverInfo);

	if (env) {
		report += env;
	}

	report += `== ${sdEdiAdminParams.logShareRunsHeading || 'Import Runs'} ==\n\n`;
	report += runs.map(runBlock).join('\n');

	return report.trimEnd() + '\n';
};

/**
 * Copies text to the clipboard, preferring the async Clipboard API and falling
 * back to a hidden textarea + execCommand on insecure contexts or rejection.
 * Mirrors the proven approach in ServerInfoCollapse. Resolves true on success.
 *
 * @param {string} text - Text to copy.
 * @return {Promise<boolean>} Whether the copy succeeded.
 */
export const copyText = async (text) => {
	if (window.isSecureContext && navigator.clipboard) {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch {
			// Fall through to the legacy path below.
		}
	}

	const textArea = document.createElement('textarea');
	textArea.value = text;
	textArea.style.position = 'fixed';
	textArea.style.opacity = '0';
	document.body.appendChild(textArea);
	textArea.select();

	try {
		return document.execCommand('copy');
	} catch {
		return false;
	} finally {
		document.body.removeChild(textArea);
	}
};

/**
 * Triggers a client-side download of a text file. No server round-trip — the
 * blob is built and released in-page.
 *
 * @param {string} filename - Suggested file name.
 * @param {string} text     - File contents.
 */
export const downloadText = (filename, text) => {
	const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
	const url = URL.createObjectURL(blob);
	const link = document.createElement('a');

	link.href = url;
	link.download = filename;
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	URL.revokeObjectURL(url);
};

/**
 * A filesystem-safe file name for a single run's export, e.g.
 * "edi-import-log-home-1.txt". Falls back to the session id when the name is
 * empty after slugifying.
 *
 * @param {Object} run - The run record.
 * @return {string} File name.
 */
export const runFileName = (run) => {
	const slug =
		runName(run)
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '') || run.session_id;

	return `edi-import-log-${slug}.txt`;
};
