<?php
/**
 * Demo Import Page.
 *
 * @package RT\DemoImport
 */

/**
 * Template variables:
 *
 * @var $args   array
 */

use RT\DemoImporter\Helpers\Fns;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

$themeConfig = $args['themeConfig'];
?>
<div id="sd-edi-demo-import-container"></div>
