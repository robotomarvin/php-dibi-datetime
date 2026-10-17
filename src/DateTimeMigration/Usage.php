<?php

declare(strict_types=1);

namespace Dibi\DateTimeMigration;


/**
 * Tracks whether object was used. Held by reference so that __clone() can mark the source object.
 * @internal
 */
final class Usage
{
	public bool $used = false;
}
