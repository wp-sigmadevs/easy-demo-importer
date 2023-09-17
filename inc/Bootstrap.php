<?php
/**
 * Class: Plugin Bootstrap.
 *
 * The main handler class responsible for initializing Easy Demo Importer.
 * This class registers all the core modules required to run the plugin.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter;

use Composer\Autoload\ClassLoader;
use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Requester
};
use SigmaDevs\EasyDemoImporter\Config\{
	I18n,
	Classes,
	Requirements
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class: Plugin Bootstrap.
 *
 * @since 1.0.0
 */
final class Bootstrap extends Base {
	/**
	 * Traits.
	 *
	 * @see Requester
	 * @since 1.0.0
	 */
	use Requester;

	/**
	 * List of services to register.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $services = [];

	/**
	 * Composer autoload file list.
	 *
	 * @var ClassLoader
	 * @since 1.0.0
	 */
	public $composer;

	/**
	 * Requirements class object.
	 *
	 * @var Requirements
	 * @since 1.0.0
	 */
	protected $requirements;

	/**
	 * I18n class object.
	 *
	 * @var I18n
	 * @since 1.0.0
	 */
	protected $i18n;

	/**
	 * Register plugin services.
	 *
	 * @param ClassLoader $composer Composer autoload output.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function registerServices( $composer ) {
		do_action( 'sd/edi/plugin_loaded' );

		// Check plugin requirements.
		$this->checkRequirements();

		// Define the locale.
		$this->setLocale();

		// class loader from Composer.
		$this->getClassLoader( $composer );

		// Load services.
		$this->loadServices( Classes::register() );
	}

	/**
	 * Check plugin requirements.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function checkRequirements() {
		$this->requirements = Requirements::instance();
		$this->requirements->check();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function setLocale() {
		$this->i18n = I18n::instance();
		$this->i18n->load();
	}

	/**
	 * Get the class loader from Composer
	 *
	 * @param object $composer Autoloader object.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function getClassLoader( $composer ) {
		$this->composer = $composer;
	}

	/**
	 * Initialize the requested services.
	 *
	 * @param array $services The loaded services.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function loadServices( $services ) {
		foreach ( $services as $service ) {
			if ( isset( $service['onRequest'] ) && is_array( $service['onRequest'] )
			) {
				foreach ( $service['onRequest'] as $onRequest ) {
					if ( ! $this->request( $onRequest ) ) {
						continue;
					}
				}
			} elseif ( isset( $service['onRequest'] ) && ! $this->request( $service['onRequest'] )
			) {
				continue;
			}

			// Get the services.
			$this->getServices( $service['register'] );
		}

		// Init the services.
		$this->initServices();
	}

	/**
	 * Get classes based on the directory automatically
	 * using the Composer autoload.
	 *
	 * This method checks for optimized class autoload to reduce
	 * server load time.
	 *
	 * @param string $service Class name to find.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getServices( string $service ) {
		$service = $this->plugin->namespace() . '\\' . $service;

		if ( is_object( $this->composer ) === false ) {
			return $this->services;
		}

		$classmap = $this->composer->getClassMap();
		$classes  = array_keys( $classmap );

		foreach ( $classes as $class ) {
			if ( 0 !== strncmp( (string) $class, $service, strlen( $service ) ) ) {
				continue;
			}

			$this->services[] = $class;
		}

		return $this->services;
	}

	/**
	 * Initialize the services.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function initServices() {
		$this->services = apply_filters( 'sd/edi/initialized_classes', $this->services );

		foreach ( $this->services as $service ) {
			$class = $service::instance();

			if ( method_exists( $class, 'register' ) ) {
				$class->register();
			}
		}
	}
}
