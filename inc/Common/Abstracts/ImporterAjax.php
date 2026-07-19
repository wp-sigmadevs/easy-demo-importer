<?php
/**
 * Abstract Class: Importer Ajax
 *
 * This abstract class is responsible for ajax import functionalities.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Abstracts;

use SigmaDevs\EasyDemoImporter\Common\Functions\{
	Helpers,
	ImportLogger,
	SessionManager
};
use SigmaDevs\EasyDemoImporter\Common\Utils\ManualContext;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Abstract Class: Importer Ajax
 *
 * @since 1.0.0
 */
abstract class ImporterAjax {
	/**
	 * Theme demo config.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $config = [];

	/**
	 * Ajax response.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $response = [];

	/**
	 * Demo slug.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $demoSlug = '';

	/**
	 * Exclude images.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $excludeImages = '';

	/**
	 * Skip image regeneration.
	 *
	 * @var bool
	 * @since 2.0.0
	 */
	public $skipImageRegeneration = false;

	/**
	 * Multiple Zip check.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $multiple = false;

	/**
	 * Uploads directory.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $uploadsDir;

	/**
	 * Database reset.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $reset;

	/**
	 * Create a restore point (snapshot) before importing.
	 *
	 * @var bool
	 * @since 2.0.0
	 */
	public $snapshot = false;

	/**
	 * Whether this is a manual import (user-uploaded files, no theme config).
	 *
	 * @var bool
	 * @since 2.0.0
	 */
	public $manual = false;

	/**
	 * Manual import working-directory key.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	public $manualKey = '';

	/**
	 * Import session ID.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	public $sessionId = '';

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		// Verify if the user has the correct role.
		Helpers::verifyUserRole();

		// Handle the Post submission.
		$this->handlePostSubmission();

		// Capture a PHP fatal (500) into the activity log before the worker dies.
		$this->registerFatalLogger();
	}

	/**
	 * Registers a shutdown probe that records a PHP fatal to the activity log.
	 *
	 * A fatal error (out of memory, max execution time, uncaught Error, parse
	 * error) kills the request before any phase can log it, so the Import Log
	 * would otherwise show only the last successful step and infer "interrupted"
	 * — never the actual cause. PHP still runs shutdown functions after a fatal,
	 * so this probe inspects error_get_last() and, when the last error is a fatal
	 * type, appends a real ERROR entry for the active session. This mirrors how
	 * WordPress core's own WP_Fatal_Error_Handler captures fatals; the
	 * wp_should_handle_php_error filter cannot be used here because core returns
	 * early for these error types before that filter ever fires.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	protected function registerFatalLogger() {
		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		$demo_slug = $this->demoSlug;

		register_shutdown_function(
			static function () use ( $demo_slug ) {
				$error = error_get_last();

				if ( null === $error ) {
					return;
				}

				$fatal_types = [
					E_ERROR,
					E_PARSE,
					E_CORE_ERROR,
					E_COMPILE_ERROR,
					E_USER_ERROR,
					E_RECOVERABLE_ERROR,
				];

				if ( ! in_array( $error['type'], $fatal_types, true ) ) {
					return;
				}

				// A fatal outside an active import (no session) belongs to some
				// other admin request — leave it out of the import timeline.
				$active     = SessionManager::get();
				$session_id = $active['session_id'] ?? '';

				if ( '' === $session_id ) {
					return;
				}

				// The message can carry an absolute file path; that is acceptable
				// in a technical record, but keep it single-line and bounded so a
				// giant stack-style message can't bloat the log row.
				$message = sprintf(
					/* translators: 1: error message, 2: file, 3: line. */
					esc_html__( 'Import failed with a fatal error: %1$s (in %2$s on line %3$d).', 'easy-demo-importer' ),
					trim( preg_replace( '/\s+/', ' ', (string) $error['message'] ) ),
					(string) $error['file'],
					(int) $error['line']
				);

				ImportLogger::maybeInstall();
				ImportLogger::error( $message, $session_id, $demo_slug );
			}
		);
	}

	/**
	 * Handles post submission.
	 *
	 * @return void
	 */
	protected function handlePostSubmission() {
		// Hard-fail on nonce mismatch — do not proceed silently.
		if ( ! check_ajax_referer( Helpers::nonceText(), Helpers::nonceId(), false ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Security check failed. Please refresh the page and try again.', 'easy-demo-importer' ),
					'errorHint'    => esc_html__( 'This can happen if you stayed on the page too long. Refreshing resets the security token.', 'easy-demo-importer' ),
				],
				403
			);
		}

		// Theme config — or, for a manual import (user-uploaded files, no theme
		// demo config), a minimal stub pointing the phases at the uploaded working
		// directory. The stub deliberately declares nothing beyond the working
		// directory, so the settings/slider/fluent-forms phases no-op.
		if ( ManualContext::isManual() ) {
			$this->config = ManualContext::configStub( ManualContext::requestKey() );
		} else {
			$this->config = sd_edi()->getDemoConfig();

			if ( empty( $this->config ) ) {
				ImportLogger::error(
					esc_html__( 'Demo configuration is missing or empty.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);

				wp_send_json_error(
					[
						'errorMessage' => esc_html__( 'Demo configuration is missing or empty.', 'easy-demo-importer' ),
						'errorHint'    => esc_html__( 'Make sure your theme is calling the sd/edi/importer/config filter and returning a valid configuration array.', 'easy-demo-importer' ),
					],
					500
				);
			}
		}

		// Uploads Directory.
		$this->uploadsDir = wp_get_upload_dir();

		// Check if multiple demo is configured.
		$this->multiple = ! empty( $this->config['multipleZip'] ) ? $this->config['multipleZip'] : false;

		// Demo slug.
		$this->demoSlug = $this->getDemoSlug();

		// Validate session ID if one was sent (all phases after Initialize).
		$posted_session_id = ! empty( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		if ( ! empty( $posted_session_id ) ) {
			if ( ! SessionManager::isValid( $posted_session_id ) ) {
				ImportLogger::error(
					esc_html__( 'The import session is no longer valid.', 'easy-demo-importer' ),
					$posted_session_id,
					$this->demoSlug
				);

				wp_send_json_error(
					[
						'errorMessage' => esc_html__( 'The import session is no longer valid.', 'easy-demo-importer' ),
						'errorHint'    => esc_html__( 'This can happen if the import timed out or another import started. Refresh the page and try again.', 'easy-demo-importer' ),
					],
					409
				);
			}

			$this->sessionId = $posted_session_id;

			// Heartbeat: this phase is genuine progress, so keep the session fresh.
			// A silent (interrupted) import stops touching this and is flagged in
			// the Import Log even before its 30-minute lock expires.
			SessionManager::touch( $this->sessionId );
		}

		// Check if images import is needed.
		$this->excludeImages = ! empty( $_POST['excludeImages'] ) ? sanitize_text_field( wp_unslash( $_POST['excludeImages'] ) ) : '';

		// Check if image regeneration should be skipped.
		$this->skipImageRegeneration = isset( $_POST['skipImageRegeneration'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['skipImageRegeneration'] ) );

		// Check if database reset needed.
		$this->reset = isset( $_POST['reset'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['reset'] ) );

		// Check if a pre-import restore point (snapshot) was requested.
		$this->snapshot = isset( $_POST['snapshot'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['snapshot'] ) );

		// Manual-import flags (carried across every phase so the config stub +
		// working directory resolve on each request).
		$this->manual    = ManualContext::isManual();
		$this->manualKey = ManualContext::requestKey();

		// A manual import that shipped its own media (an images .zip, or a bundle
		// with an uploads/ folder) stages those files under <working-dir>/uploads.
		// The UI hides the "Import Demo Images" toggle in that case, so
		// excludeImages arrives as 'true' and would skip every attachment. Force
		// it off so the bundled-media path attaches the staged files locally —
		// creating real Media Library entries — instead of dropping raw files on
		// disk. Applied on every phase so the content-import and regeneration
		// steps agree.
		if ( $this->manual ) {
			$staged = $this->demoUploadDir( $this->demoDir() ) . '/uploads';

			if ( is_dir( $staged ) && ( new \FilesystemIterator( $staged ) )->valid() ) {
				$this->excludeImages = 'false';
			}
		}
	}

	/**
	 * Get demo slug.
	 *
	 * @return int|string|null
	 *
	 * @since 1.0.0
	 */
	private function getDemoSlug() {
		// Nonce already verified in handlePostSubmission() before this is called.
		$firstDemoSlug = ! empty( $this->config['demoData'] ) ? array_key_first( $this->config['demoData'] ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['demo'] ) ) {
			return $firstDemoSlug;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slug = sanitize_text_field( wp_unslash( $_POST['demo'] ) );

		// For multi-demo themes, reject slugs that don't exist in the config.
		if ( ! empty( $this->config['demoData'] ) && ! array_key_exists( $slug, $this->config['demoData'] ) ) {
			wp_send_json_error( [ 'errorMessage' => __( 'Invalid demo selection.', 'easy-demo-importer' ) ], 400 );
		}

		return $slug;
	}

	/**
	 * Prepare ajax response.
	 *
	 * @param string      $nextPhase Next phase.
	 * @param string      $nextPhaseMessage Next phase message.
	 * @param string      $complete Completed message, shown as the modal card text.
	 * @param bool        $error Error.
	 * @param string      $errorMessage Error message.
	 * @param string      $errorHint Error hint.
	 * @param string|null $logMessage Activity-log text for this completion, if it
	 *                                should read differently there than the modal's
	 *                                friendlier $complete text (e.g. no idioms, since
	 *                                the log is a technical record). Defaults to
	 *                                $complete when omitted.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function prepareResponse( $nextPhase, $nextPhaseMessage, $complete = '', $error = false, $errorMessage = '', $errorHint = '', $logMessage = null ) {
		$this->response = [
			'demo'                  => $this->demoSlug,
			'excludeImages'         => $this->excludeImages,
			'skipImageRegeneration' => $this->skipImageRegeneration,
			'reset'                 => $this->reset,
			'snapshot'              => $this->snapshot,
			'manual'                => $this->manual ? 'true' : 'false',
			'manualKey'             => $this->manualKey,
			'sessionId'             => $this->sessionId,
			'nextPhase'             => $nextPhase,
			'nextPhaseMessage'      => $nextPhaseMessage,
			'completedMessage'      => $complete,
			'error'                 => $error,
			'errorMessage'          => $errorMessage,
			'errorHint'             => $errorHint,
		];

		// Mirror every phase outcome to the activity log — one place covers all
		// wizard phases. Errors log as error; the terminal completion (no next
		// phase) logs as success; every other completed step logs as info.
		if ( $error ) {
			if ( '' !== $errorMessage ) {
				ImportLogger::error( $errorMessage, $this->sessionId, $this->demoSlug );
			}
		} elseif ( '' !== $complete ) {
			$logText = $logMessage ?? $complete;

			if ( '' === $nextPhase ) {
				ImportLogger::success( $logText, $this->sessionId, $this->demoSlug );
			} else {
				ImportLogger::info( $logText, $this->sessionId, $this->demoSlug );
			}
		}

		$this->sendResponse();
	}

	/**
	 * Send ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function sendResponse() {
		wp_send_json( $this->response );
	}

	/**
	 * Demo upload path.
	 *
	 * @param string $path Path.
	 *
	 * @return string|void
	 * @since 1.0.0
	 */
	public function demoUploadDir( $path = '' ) {
		if ( is_array( $this->uploadsDir ) && isset( $this->uploadsDir['basedir'] ) ) {
			return $this->uploadsDir['basedir'] . '/easy-demo-importer/' . $path;
		}
	}

	/**
	 * Demo directory.
	 *
	 * @return array|string
	 * @since 1.0.0
	 */
	public function demoDir() {
		$demoZip = $this->multiple ? Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'demoZip' ) : Helpers::getDemoData( $this->config, 'demoZip' );

		return pathinfo( basename( $demoZip ), PATHINFO_FILENAME );
	}

	/**
	 * Check if the plugin is active or not.
	 *
	 * @param string $path Plugin path.
	 *
	 * @return string
	 */
	protected function pluginStatus( $path ) {
		$status = 'install';

		$pluginPath = WP_PLUGIN_DIR . '/' . $path;

		if ( file_exists( $pluginPath ) ) {
			$status = is_plugin_active( $path ) ? 'active' : 'inactive';

			require_once ABSPATH . 'wp-admin/includes/update.php';

			$update_list = get_site_transient( 'update_plugins' );

			if ( isset( $update_list->response[ $path ] ) ) {
				$status = 'update';
			}
		}

		return $status;
	}

	/**
	 * Ajax response.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	abstract public function response();

	/**
	 * Unzips and imports slider files.
	 *
	 * @param string   $sliderFileKey The key to retrieve slider zip from config (e.g., 'layerSliderZip', 'revSliderZip').
	 * @param callable $importCallback The callback function to execute the specific slider import logic.
	 * @param string   $customExtractDir Optional. The custom directory to extract files to. If null, a default is used.
	 *
	 * @return bool True if import was attempted and successful, false otherwise.
	 * @since 2.0.0
	 */
	protected function unzipAndImportSlider( $sliderFileKey, $importCallback, $customExtractDir = null ) {
		$slider = basename(
			$this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], $sliderFileKey ) :
			Helpers::getDemoData( $this->config, $sliderFileKey )
		);

		$defaultExtractDir = $this->demoUploadDir( $this->demoDir() ) . '/' . $slider;
		$targetExtractDir  = $customExtractDir ?? $defaultExtractDir;

		$sliderFileExists = file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip' );

		if ( ! $slider || ! $sliderFileExists ) {
			return false;
		}

		$sliderPath = $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip';
		$zip        = new \ZipArchive();

		if ( $zip->open( $sliderPath ) === true ) {
			// Validate every ZIP entry before extracting to prevent ZipSlip.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$entry_name = $zip->getNameIndex( $i );

				// Reject path-traversal sequences and absolute paths.
				if ( false !== strpos( $entry_name, '..' ) ||
					'/' === substr( $entry_name, 0, 1 ) ||
					'\\' === substr( $entry_name, 0, 1 ) ) {
					$zip->close();
					return false;
				}
			}

			wp_mkdir_p( $targetExtractDir );
			$zip->extractTo( $targetExtractDir );
			$zip->close();

			// Report success based on what the callback actually imported, not
			// merely that the ZIP extracted -- otherwise the UI and activity log
			// claim slides were imported even when the slider plugin is inactive
			// and the callback imported nothing.
			return (bool) call_user_func( $importCallback, $targetExtractDir, $slider );
		}

		return false;
	}
}
