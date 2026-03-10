<?php
/**
 * Rector configuration
 */

declare( strict_types=1 );

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

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
