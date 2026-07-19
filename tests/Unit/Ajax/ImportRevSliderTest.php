<?php
/**
 * Unit tests for the RevSlider import delegation.
 *
 * Verifies the version-correct API selection (Slider Revolution 6+
 * RevSliderSliderImport::import_slider) and the honest return contract: true
 * only when a slider is actually imported, false on an empty extract.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Ajax {

	use ReflectionClass;
	use SigmaDevs\EasyDemoImporter\App\Ajax\Backend\ImportRevSlider;
	use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

	/**
	 * @covers \SigmaDevs\EasyDemoImporter\App\Ajax\Backend\ImportRevSlider
	 */
	final class ImportRevSliderTest extends UnitTestCase {

		/**
		 * Directories created during a test, removed afterwards.
		 *
		 * @var string[]
		 */
		private $tmpDirs = [];

		/**
		 * @inheritDoc
		 */
		protected function tear_down() {
			foreach ( $this->tmpDirs as $dir ) {
				array_map( 'unlink', glob( $dir . '/*/*' ) ?: [] );
				array_map( 'rmdir', glob( $dir . '/*' ) ?: [] );

				if ( is_dir( $dir ) ) {
					rmdir( $dir );
				}
			}
			$this->tmpDirs = [];
			parent::tear_down();
		}

		/**
		 * Builds an ImportRevSlider without running the WP-heavy constructor.
		 *
		 * @return ImportRevSlider
		 */
		private function importer(): ImportRevSlider {
			return ( new ReflectionClass( ImportRevSlider::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * Creates <root>/<name>/ and returns the root path.
		 *
		 * @param string $name      Slider sub-directory name.
		 * @param bool   $withZip   Whether to drop a dummy .zip inside it.
		 *
		 * @return string Extract root.
		 */
		private function extractRoot( string $name, bool $withZip ): string {
			$root = sys_get_temp_dir() . '/edi-revslider-' . uniqid();
			mkdir( $root . '/' . $name, 0777, true );

			if ( $withZip ) {
				file_put_contents( $root . '/' . $name . '/slider.zip', 'dummy' );
			}

			$this->tmpDirs[] = $root;

			return $root;
		}

		public function test_returns_false_when_no_slider_zip_present() {
			$root = $this->extractRoot( 'myslider', false );

			self::assertFalse(
				$this->invoke( $this->importer(), 'importRevSlider', [ $root, 'myslider' ] )
			);
		}

		public function test_delegates_to_slider_revolution_6_api_and_reports_success() {
			\RevSliderSliderImport::$calls = [];

			$root   = $this->extractRoot( 'myslider', true );
			$result = $this->invoke( $this->importer(), 'importRevSlider', [ $root, 'myslider' ] );

			self::assertTrue( $result, 'A present slider must report a successful import.' );
			self::assertCount( 1, \RevSliderSliderImport::$calls, 'import_slider must run once per zip.' );
			self::assertStringEndsWith( 'slider.zip', \RevSliderSliderImport::$calls[0] );
		}
	}
}

namespace {

	// Stub for the Slider Revolution 6+ import API. Defining it makes
	// class_exists('RevSliderSliderImport') true so the importer takes the
	// modern path (the one the b2c34e6 fix switched to).
	if ( ! class_exists( 'RevSliderSliderImport' ) ) {
		class RevSliderSliderImport {

			/**
			 * Records each import call's file path.
			 *
			 * @var array
			 */
			public static $calls = [];

			/**
			 * @param bool        $update_animation Update flag.
			 * @param string|bool $exact_filepath   Zip path.
			 *
			 * @return bool
			 */
			public function import_slider( $update_animation = true, $exact_filepath = false ) {
				self::$calls[] = (string) $exact_filepath;

				return true;
			}
		}
	}
}
