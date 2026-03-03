<?php
/**
 * Rector configuration
 */

declare( strict_types=1 );

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

return RectorConfig::configure()
	->withPaths(
		[
			__DIR__ . '/inc',
		]
	)
	->withSets(
		[
			SetList::PHP_84,
		]
	);
