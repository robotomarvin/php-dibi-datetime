<?php

declare(strict_types=1);

namespace Dibi\DateTimeMigration;


/**
 * Objects that are one single object in dibi 3 (returned by mutators of each other)
 * and therefore must share state.
 * @internal
 */
final class AliasGroup
{
	/** @var \WeakMap<\Dibi\DateTime, true> */
	public \WeakMap $members;


	public function __construct()
	{
		$this->members = new \WeakMap;
	}
}
