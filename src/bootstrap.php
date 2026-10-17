<?php

declare(strict_types=1);

// Fails loudly when the original dibi class is loaded instead of the shim
// (missing "exclude-from-classmap" in the root composer.json).
if (!defined(Dibi\DateTime::class . '::MIGRATION_SHIM')) {
	trigger_error(
		'Dibi\DateTime migration shim is not active, original ' . (new ReflectionClass(Dibi\DateTime::class))->getFileName()
		. ' was loaded. Add "exclude-from-classmap": ["vendor/dibi/dibi/src/Dibi/DateTime.php"] to the root composer.json autoload section.',
		E_USER_WARNING,
	);
}
