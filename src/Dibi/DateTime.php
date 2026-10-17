<?php

declare(strict_types=1);

namespace Dibi;

use Dibi\DateTimeMigration\AliasGroup;
use Dibi\DateTimeMigration\Reporter;
use Dibi\DateTimeMigration\Usage;


/**
 * Drop-in replacement of dibi 3 Dibi\DateTime used during migration to dibi 4/5.
 *
 * Behaves exactly as the mutable dibi 3 class, but every object also carries a "shadow"
 * DateTimeImmutable holding the value the same variable would have in dibi 4/5.
 * Mutators return a new object that shares state with the original (so dibi 3 semantics are kept),
 * and every read compares the real value with the shadow one and reports when they differ.
 */
class DateTime extends \DateTime
{
	use Strict;

	/** marker checked by bootstrap.php */
	public const MIGRATION_SHIM = true;

	private const STATE_FORMAT = 'Y-m-d H:i:s.u e P';

	/** value this object would hold in dibi 4/5 */
	private ?\DateTimeImmutable $shadow = null;

	/** @var array<string, string>  call site => mutating call that made the real value differ from $shadow */
	private array $divergedBy = [];

	/** objects that are a single object in dibi 3 */
	private ?AliasGroup $group = null;

	private ?Usage $usage = null;

	/** mutating call that created this object, for discarded result detection */
	private ?string $createdBy = null;

	private ?string $createdAt = null;


	/**
	 * @param  string|int  $time
	 */
	public function __construct($time = 'now', ?\DateTimeZone $timezone = null)
	{
		if ($time === null) {
			Reporter::report(
				Reporter::WOULD_THROW,
				'new Dibi\DateTime(null) at ' . ($site = Reporter::callSite()) . ' throws TypeError in dibi 5.',
				['site' => $site],
			);
			$time = 'now';
		}

		if (is_numeric($time)) {
			parent::__construct('@' . $time);
			parent::setTimezone($timezone ?: new \DateTimeZone(date_default_timezone_get()));
		} elseif ($timezone === null) {
			parent::__construct($time);
		} else {
			parent::__construct($time, $timezone);
		}
		$this->shadow = \DateTimeImmutable::createFromMutable($this);
	}


	/** @deprecated  removed in dibi 5, use modify() */
	public function modifyClone($modify = '')
	{
		$site = Reporter::callSite();
		Reporter::report(
			Reporter::REMOVED_API,
			"modifyClone() at $site does not exist in dibi 5, use modify().",
			['site' => $site],
		);

		$this->usage()->used = true;
		$dolly = clone $this;
		if (!$modify) {
			return $dolly;
		}
		$result = $dolly->mutate('modify', [$modify]);
		if ($result) {
			$result->createdBy = null; // discarding result of modifyClone() is harmless in both versions
		}
		return $result;
	}


	/********************* mutators ****************d*g**/


	#[\ReturnTypeWillChange]
	public function modify(string $modifier)
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	#[\ReturnTypeWillChange]
	public function add(\DateInterval $interval)
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	#[\ReturnTypeWillChange]
	public function sub(\DateInterval $interval)
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	#[\ReturnTypeWillChange]
	public function setTimezone(\DateTimeZone $timezone)
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	#[\ReturnTypeWillChange]
	public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0)
	{
		return $this->mutate(__FUNCTION__, [$hour, $minute, $second, $microsecond]);
	}


	#[\ReturnTypeWillChange]
	public function setDate(int $year, int $month, int $day)
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	#[\ReturnTypeWillChange]
	public function setISODate(int $year, int $week, int $dayOfWeek = 1)
	{
		return $this->mutate(__FUNCTION__, [$year, $week, $dayOfWeek]);
	}


	/**
	 * @param  int|float|string  $timestamp  dibi 3 accepts also fractional timestamps, dibi 5 only int
	 */
	#[\ReturnTypeWillChange]
	public function setTimestamp($timestamp)
	{
		if (!is_int($timestamp) && Reporter::$enabled) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::WOULD_THROW,
				'setTimestamp(' . self::describe($timestamp) . ") at $site requires int in dibi 5 (TypeError with strict_types, fraction is lost otherwise).",
				['site' => $site],
			);
		}
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	/** PHP 8.4+ */
	public function setMicrosecond(int $microsecond): static
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	/********************* readers ****************d*g**/


	public function format(string $format): string
	{
		$real = parent::format($format);
		$this->verifyRead('format(' . self::describe($format) . ')', $real, fn(\DateTimeImmutable $s) => $s->format($format));
		return $real;
	}


	public function getTimestamp(): int
	{
		$real = parent::getTimestamp();
		$this->verifyRead('getTimestamp()', $real, fn(\DateTimeImmutable $s) => $s->getTimestamp());
		return $real;
	}


	public function getOffset(): int
	{
		$real = parent::getOffset();
		$this->verifyRead('getOffset()', $real, fn(\DateTimeImmutable $s) => $s->getOffset());
		return $real;
	}


	public function getTimezone(): \DateTimeZone|false
	{
		$real = parent::getTimezone();
		$this->verifyRead('getTimezone()', $real, fn(\DateTimeImmutable $s) => $s->getTimezone());
		return $real;
	}


	/** PHP 8.4+ */
	public function getMicrosecond(): int
	{
		$real = parent::getMicrosecond();
		$this->verifyRead('getMicrosecond()', $real, fn(\DateTimeImmutable $s) => $s->getMicrosecond());
		return $real;
	}


	public function diff(\DateTimeInterface $targetObject, bool $absolute = false): \DateInterval
	{
		$real = parent::diff($targetObject, $absolute);
		$target = $targetObject instanceof self ? $targetObject : null;
		if ($target) {
			$target->usage()->used = true;
		}
		$this->verifyRead(
			'diff()',
			$real,
			fn(\DateTimeImmutable $s) => $s->diff($target ? $target->shadow() : $targetObject, $absolute),
			$target,
		);
		return $real;
	}


	public function __toString(): string
	{
		$real = parent::format('Y-m-d H:i:s.u');
		$this->verifyRead('__toString()', $real, fn(\DateTimeImmutable $s) => $s->format('Y-m-d H:i:s.u'));
		return $real;
	}


	/********************* object lifecycle ****************d*g**/


	public function __clone()
	{
		// Usage is still shared with the source here, so this marks the source as used
		if ($this->usage) {
			$this->usage->used = true;
		}
		$this->usage = null;
		$this->group = null;
		$this->createdBy = $this->createdAt = null;
	}


	public function __destruct()
	{
		if ($this->createdBy !== null && !$this->usage?->used && Reporter::$reportDiscarded) {
			try {
				Reporter::report(
					Reporter::DISCARDED_RESULT,
					"result of $this->createdBy at $this->createdAt was never used, in dibi 5 this call does not change the original object.",
					['site' => (string) $this->createdAt],
				);
			} catch (\Throwable $e) { // exception thrown from destructor is fatal during shutdown
				error_log($e->getMessage());
			}
		}
	}


	/** Same format as \DateTime and dibi 5 Dibi\DateTime, without the shim state. */
	public function __serialize(): array
	{
		$this->verifyRead('serialize()', self::nativeData($this), fn(\DateTimeImmutable $s) => self::nativeData($s));
		$data = method_exists(\DateTime::class, '__serialize') ? parent::__serialize() : (array) $this;
		$prefix = "\0" . self::class . "\0";
		return array_filter($data, fn($key) => !str_starts_with((string) $key, $prefix), ARRAY_FILTER_USE_KEY);
	}


	public function __unserialize(array $data): void
	{
		if (method_exists(\DateTime::class, '__unserialize')) {
			parent::__unserialize($data);
		} else { // PHP < 8.2, restores only public properties of subclasses
			parent::__construct($data['date'], new \DateTimeZone($data['timezone']));
			foreach (array_diff_key($data, ['date' => 0, 'timezone_type' => 0, 'timezone' => 0]) as $key => $value) {
				if (!str_starts_with((string) $key, "\0")) {
					$this->$key = $value;
				}
			}
		}
		$this->shadow = \DateTimeImmutable::createFromMutable($this);
	}


	public function __debugInfo(): array
	{
		return self::nativeData($this);
	}


	/********************* internals ****************d*g**/


	/**
	 * Applies mutation with dibi 3 semantics (all aliases change) and returns a new alias
	 * holding the dibi 4/5 value of the call result.
	 */
	private function mutate(string $method, array $args): static|false
	{
		$this->usage()->used = true;
		if (!Reporter::$enabled) {
			if ($this->applyNative($method, $args) === false) {
				return false;
			}
			foreach ($this->group?->members ?? [] as $member => $_) { // aliases created while enabled
				if ($member !== $this) {
					$member->copyNativeState($this);
				}
			}
			return $this;
		}

		$site = Reporter::callSite();
		$call = $method . '(' . implode(', ', array_map([self::class, 'describe'], $args)) . ')';
		$shadow = $this->shadow();
		$wasInSync = $this->isInSync();

		if ($this->applyNative($method, $args) === false) { // PHP < 8.3 returns false for invalid modify(), in both versions
			return false;
		}

		$result = clone $this;
		$shadowError = null;
		try {
			$result->shadow = $wasInSync
				? \DateTimeImmutable::createFromMutable($result) // exact, avoids drift of relative formats like 'now'
				: $shadow->$method(...$args);
		} catch (\Throwable $shadowError) {
			$result->shadow = \DateTimeImmutable::createFromMutable($result);
		}
		$result->divergedBy = $result->isInSync() ? [] : $this->divergedBy;
		$result->createdBy = $call;
		$result->createdAt = $site;

		$this->group ??= self::createGroup($this);
		$result->group = $this->group;
		foreach ($this->group->members as $member => $_) {
			if ($member !== $this) {
				$member->copyNativeState($this);
			}
			$member->refreshDivergence($site, $call);
		}
		$this->group->members[$result] = true;

		if ($shadowError) { // reported after aliases are updated, handler may throw
			Reporter::report(
				Reporter::WOULD_THROW,
				"$call at $site throws " . get_class($shadowError) . " in dibi 5: {$shadowError->getMessage()}",
				['site' => $site],
			);
		}
		return $result;
	}


	private function applyNative(string $method, array $args): \DateTime|false
	{
		if ($method === 'setTimestamp') { // dibi 3 implementation, keeps fraction of float/string timestamp
			$zone = parent::getTimezone();
			parent::__construct('@' . $args[0]);
			return parent::setTimezone($zone);
		}
		return match ($method) {
			'modify' => parent::modify(...$args),
			'add' => parent::add(...$args),
			'sub' => parent::sub(...$args),
			'setTimezone' => parent::setTimezone(...$args),
			'setTime' => parent::setTime(...$args),
			'setDate' => parent::setDate(...$args),
			'setISODate' => parent::setISODate(...$args),
			'setMicrosecond' => parent::setMicrosecond(...$args),
		};
	}


	/** Copies instant and timezone without calling overridden methods of $source. */
	private function copyNativeState(self $source): void
	{
		$state = \DateTimeImmutable::createFromMutable($source);
		$utc = $state->setTimezone(new \DateTimeZone('UTC')); // UTC avoids DST ambiguity
		[$y, $m, $d, $h, $i, $s, $u] = array_map('intval', explode(' ', $utc->format('Y n j G i s u'))); // no string parsing, works for years > 9999
		parent::setTimezone($utc->getTimezone());
		parent::setDate($y, $m, $d);
		parent::setTime($h, $i, $s, $u);
		parent::setTimezone($state->getTimezone());
	}


	private function refreshDivergence(string $site, string $call): void
	{
		if ($this->isInSync()) {
			$this->divergedBy = [];
		} elseif (!isset($this->divergedBy[$site]) && count($this->divergedBy) < 5) {
			$this->divergedBy[$site] = $call;
		}
	}


	/**
	 * @param  \Closure(\DateTimeImmutable): mixed  $immutable  computes the read on dibi 4/5 value
	 */
	private function verifyRead(string $call, mixed $real, \Closure $immutable, ?self $other = null): void
	{
		$this->usage()->used = true;
		if (!Reporter::$enabled || ($this->isInSync() && (!$other || $other->isInSync()))) {
			return;
		}

		try {
			$expected = self::describe($immutable($this->shadow()));
		} catch (\Throwable $e) {
			$expected = 'exception ' . get_class($e) . ': ' . $e->getMessage();
		}
		if (self::describe($real) === $expected) {
			return;
		}

		$mutations = $this->divergedBy + ($other ? $other->divergedBy : []);
		$site = Reporter::callSite();
		Reporter::report(
			Reporter::DIVERGED_READ,
			"$call at $site returns " . self::describe($real) . ", in dibi 5 it returns $expected. The object was changed in place by "
				. ($mutations
					? implode(', ', array_map(fn($site, $call) => "$call at $site", array_keys($mutations), $mutations))
					: 'an untracked call (procedural date_*() function?)')
				. '.',
			['site' => $site, 'mutations' => implode(';', array_keys($mutations))],
		);
	}


	private function isInSync(): bool
	{
		return date_format($this, self::STATE_FORMAT) === $this->shadow()->format(self::STATE_FORMAT);
	}


	private function shadow(): \DateTimeImmutable
	{
		// objects created without constructor (createFromFormat(), __set_state(), ...) are initialized lazily
		return $this->shadow ??= \DateTimeImmutable::createFromMutable($this);
	}


	private function usage(): Usage
	{
		return $this->usage ??= new Usage;
	}


	private static function createGroup(self $first): AliasGroup
	{
		$group = new AliasGroup;
		$group->members[$first] = true;
		return $group;
	}


	/** @return array{date?: string, timezone_type?: int, timezone?: string} */
	private static function nativeData(\DateTimeInterface $date): array
	{
		return array_intersect_key((array) $date, ['date' => 0, 'timezone_type' => 0, 'timezone' => 0]);
	}


	private static function describe(mixed $value): string
	{
		return match (true) {
			$value instanceof \DateTimeZone => $value->getName(),
			$value instanceof \DateInterval => $value->format('%RP%yY%mM%dDT%hH%iM%sS.%F (days: %a)'),
			$value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->format(self::STATE_FORMAT),
			is_array($value) => (string) json_encode($value),
			default => var_export($value, true),
		};
	}
}
