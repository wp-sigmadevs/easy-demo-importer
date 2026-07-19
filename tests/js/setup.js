/**
 * Global test setup.
 *
 * Api.js reads `sdEdiAdminParams` at module-load time (it builds the Axios
 * instance from it), so the global must exist before any test module imports
 * it. This file runs before the test files are evaluated.
 */
globalThis.sdEdiAdminParams = {
	restApiUrl: 'https://example.test/wp-json/',
	restNonce: 'rest-nonce',
	ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
	sd_edi_nonce: 'edi-nonce',
	importSuccess: 'Import complete!',
	importBusyTitle: 'Another import is still running.',
	importBusyHint: 'It may have stalled.',
	importWaitingMessage: 'Waiting for another import to finish',
};
