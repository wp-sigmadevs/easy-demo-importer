<?php
/**
 * Unit tests for the LayerSlider import guard.
 *
 * Verifies the honest return contract: when LayerSlider is not active the
 * importer reports false (nothing imported) instead of the old behaviour where
 * a successful ZIP extract alone was reported as "slides imported".
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Ajax;

use ReflectionClass;
use SigmaDevs\EasyDemoImporter\App\Ajax\Backend\ImportLayerSlider;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\App\Ajax\Backend\ImportLayerSlider
 */
final class ImportLayerSliderTest extends UnitTestCase {

	/**
	 * Builds an ImportLayerSlider without running the WP-heavy constructor.
	 *
	 * @return ImportLayerSlider
	 */
	private function importer(): ImportLayerSlider {
		return ( new ReflectionClass( ImportLayerSlider::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * With LayerSlider inactive (LS_Sliders undefined), nothing is imported and
	 * the method must return false so the UI/log don't claim a false success.
	 */
	public function test_returns_false_when_layerslider_inactive() {
		self::assertFalse( class_exists( 'LS_Sliders' ), 'Precondition: LayerSlider must be absent in the test process.' );

		self::assertFalse(
			$this->invoke( $this->importer(), 'importLayerSlider', [ sys_get_temp_dir() ] )
		);
	}
}
