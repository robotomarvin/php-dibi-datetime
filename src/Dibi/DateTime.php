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
 *
 * Return types of overridden methods are left tentative (as in \DateTime), so subclasses without
 * return types keep compiling like with dibi 3.
 */
class DateTime extends \DateTime
{
	use Strict {
		__get as private strictGet;
		__call as private strictCall;
		extensionMethod as private strictExtensionMethod;
	}

	/** marker checked by bootstrap.php */
	public const MIGRATION_SHIM = true;

	private const STATE_FORMAT = 'Y-m-d H:i:s.u e P';

	/** value this object would hold in dibi 4/5 */
	private ?\DateTimeImmutable $shadow = null;

	/** cached STATE_FORMAT of $shadow */
	private ?string $shadowState = null;

	private ?\DateTimeImmutable $shadowStateOf = null;

	/** @var array<string, string>  call site => call that made the real value differ from $shadow */
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
		$site = null;
		$dibi5Time = $time; // argument as dibi 5 constructor receives it, null when it throws
		if ($time === null) {
			Reporter::report(
				Reporter::WOULD_THROW,
				'new Dibi\DateTime(null) at ' . ($site = Reporter::callSite()) . ' throws TypeError in dibi 5.',
				['site' => $site, 'call' => 'new(null)'],
			);
			$time = 'now';
		} elseif (!is_string($time) && !is_int($time) && Reporter::$enabled) {
			$site = Reporter::callSite();
			if (Reporter::isStrictCaller()) {
				Reporter::report(
					Reporter::WOULD_THROW,
					'new Dibi\DateTime(' . self::describe($time) . ") at $site throws TypeError in dibi 5, it accepts only string|int.",
					['site' => $site, 'call' => 'new(' . get_debug_type($time) . ')'],
				);
				$dibi5Time = null;
			} else { // coercion to string|int parameter, false becomes 0 = 1970-01-01
				$dibi5Time = match (true) {
					is_float($time) && abs($time) >= PHP_INT_MAX => (string) $time,
					is_float($time), is_bool($time) => (int) $time,
					$time instanceof \Stringable => (string) $time,
					default => null,
				};
			}
		}
		if (is_bool($time) || $time instanceof \Stringable) {
			$time = (string) $time; // dibi 3 class is not in strict_types mode
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

		if (Reporter::$enabled && $dibi5Time !== null && (is_numeric($dibi5Time) || $dibi5Time !== $time)) {
			$this->initDibi5Construct($dibi5Time, $timezone, $site);
		}
	}


	/** @deprecated  removed in dibi 5, use modify() */
	public function modifyClone($modify = '')
	{
		if (Reporter::$enabled) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::REMOVED_API,
				"modifyClone() at $site does not exist in dibi 5, use modify().",
				['site' => $site, 'call' => 'modifyClone'],
			);
		}

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


	/** @deprecated  removed in dibi 5, Dibi\DateTime extends DateTimeImmutable there */
	#[\ReturnTypeWillChange]
	public static function createFromImmutable(\DateTimeImmutable $object)
	{
		if (Reporter::$enabled) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::REMOVED_API,
				"Dibi\\DateTime::createFromImmutable() at $site does not exist in dibi 5, use createFromInterface().",
				['site' => $site, 'call' => 'createFromImmutable'],
			);
		}
		return self::track(parent::createFromImmutable($object));
	}


	public static function createFromInterface(\DateTimeInterface $object): \DateTime
	{
		return self::track(parent::createFromInterface($object));
	}


	#[\ReturnTypeWillChange]
	public static function createFromFormat(string $format, string $datetime, ?\DateTimeZone $timezone = null)
	{
		return self::track(parent::createFromFormat($format, $datetime, $timezone));
	}


	/********************* dibi 3 Strict trait, missing in dibi 5 ****************d*g**/


	/** Magic properties $date->timestamp, $date->timezone, $date->offset, ... */
	public function &__get($name)
	{
		if (Reporter::$enabled
			&& (method_exists($this, $m = 'get' . $name) || method_exists($this, $m = 'is' . $name))
			&& (new \ReflectionMethod($this, $m))->isPublic()
		) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::REMOVED_API,
				"\$date->$name at $site reads magic property of dibi 3, in dibi 5 it is undefined (Warning, returns null). Use $m().",
				['site' => $site, 'call' => '->' . $name],
			);
		}
		$value = $this->strictGet($name);
		return $value;
	}


	public function __call($name, $args)
	{
		if (Reporter::$enabled && self::strictExtensionMethod(get_class($this) . '::' . $name)) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::REMOVED_API,
				"$name() at $site is an extension method, dibi 5 has no extension methods (Error: Call to undefined method).",
				['site' => $site, 'call' => $name . '()'],
			);
		}
		return $this->strictCall($name, $args);
	}


	public static function extensionMethod($name, $callback = null)
	{
		if ($callback !== null && Reporter::$enabled) {
			$site = Reporter::callSite();
			Reporter::report(
				Reporter::REMOVED_API,
				"Dibi\\DateTime::extensionMethod() at $site does not exist in dibi 5.",
				['site' => $site, 'call' => 'extensionMethod'],
			);
		}
		return self::strictExtensionMethod($name, $callback);
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
		$shadowArgs = null;
		if (!is_int($timestamp) && Reporter::$enabled) {
			$shadowArgs = (is_numeric($timestamp) || is_bool($timestamp)) && !Reporter::isStrictCaller()
				? [(int) $timestamp] // coercion to int parameter, fraction is lost
				: [$timestamp]; // shadow call throws TypeError, reported by mutate()
		}
		return $this->mutate(__FUNCTION__, func_get_args(), $shadowArgs);
	}


	/** PHP 8.4+, native return type is not tentative */
	public function setMicrosecond(int $microsecond): static
	{
		return $this->mutate(__FUNCTION__, func_get_args());
	}


	/********************* readers ****************d*g**/


	#[\ReturnTypeWillChange]
	public function format(string $format)
	{
		$real = parent::format($format);
		$this->verifyRead('format(' . self::describe($format) . ')', $real, fn(\DateTimeImmutable $s) => $s->format($format));
		return $real;
	}


	#[\ReturnTypeWillChange]
	public function getTimestamp()
	{
		$real = parent::getTimestamp();
		$this->verifyRead('getTimestamp()', $real, fn(\DateTimeImmutable $s) => $s->getTimestamp());
		return $real;
	}


	#[\ReturnTypeWillChange]
	public function getOffset()
	{
		$real = parent::getOffset();
		$this->verifyRead('getOffset()', $real, fn(\DateTimeImmutable $s) => $s->getOffset());
		return $real;
	}


	#[\ReturnTypeWillChange]
	public function getTimezone()
	{
		$real = parent::getTimezone();
		$this->verifyRead('getTimezone()', $real, fn(\DateTimeImmutable $s) => $s->getTimezone());
		return $real;
	}


	/** PHP 8.4+, native return type is not tentative */
	public function getMicrosecond(): int
	{
		$real = parent::getMicrosecond();
		$this->verifyRead('getMicrosecond()', $real, fn(\DateTimeImmutable $s) => $s->getMicrosecond());
		return $real;
	}


	#[\ReturnTypeWillChange]
	public function diff(\DateTimeInterface $targetObject, bool $absolute = false)
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
					['site' => (string) $this->createdAt, 'call' => strstr($this->createdBy, '(', true)],
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
		return self::withoutShimState($data);
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
		return self::withoutShimState((array) $this) + self::nativeData($this);
	}


	/********************* internals ****************d*g**/


	/**
	 * Applies mutation with dibi 3 semantics (all aliases change) and returns a new alias
	 * holding the dibi 4/5 value of the call result.
	 * @param  ?array  $shadowArgs  arguments as dibi 5 receives them after type coercion
	 */
	private function mutate(string $method, array $args, ?array $shadowArgs = null): static|false
	{
		if ($this->group && !isset($this->group->members[$this])) { // clone made by subclass __clone() not calling parent
			$this->group = $this->usage = $this->createdBy = $this->createdAt = null;
		}
		$this->usage()->used = true;
		if (!Reporter::$enabled) {
			if ($this->applyNative($method, $args) === false) {
				return false;
			}
			if ($this->group) { // aliases created while enabled
				$state = self::nativeState($this);
				foreach ($this->group->members as $member => $_) {
					if ($member !== $this) {
						$member->copyNativeState($state);
					}
				}
			}
			return $this;
		}

		$site = Reporter::callSite();
		$call = $method . '(' . implode(', ', array_map([self::class, 'describe'], $args)) . ')';
		$shadow = $this->shadow();
		$before = date_format($this, self::STATE_FORMAT);
		$wasInSync = $before === $this->shadowState();

		if ($this->applyNative($method, $args) === false) { // PHP < 8.3 returns false for invalid modify(), in both versions
			return false;
		}

		$result = clone $this;
		$result->usage = new Usage; // created eagerly, so that clone of the result marks it as used
		$shadowError = null;
		try {
			$result->shadow = $wasInSync && $shadowArgs === null
				? \DateTimeImmutable::createFromMutable($result) // exact, avoids drift of relative formats like 'now'
				: $shadow->$method(...($shadowArgs ?? $args));
		} catch (\Throwable $shadowError) {
			$result->shadow = \DateTimeImmutable::createFromMutable($result);
		}
		$after = date_format($this, self::STATE_FORMAT);
		$result->divergedBy = $result->isInSync() ? [] : $this->divergedBy;
		if ($after !== $before) { // no-op call behaves the same in dibi 5 even when the result is discarded
			$result->createdBy = $call;
			$result->createdAt = $site;
		}

		$this->group ??= self::createGroup($this);
		$result->group = $this->group;
		$state = self::nativeState($this);
		foreach ($this->group->members as $member => $_) {
			if ($member !== $this) {
				$member->copyNativeState($state);
			}
			$member->refreshDivergence($site, $call, $after);
		}
		$this->group->members[$result] = true;

		if ($shadowError) { // reported after aliases are updated, handler may throw
			Reporter::report(
				Reporter::WOULD_THROW,
				"$call at $site throws " . get_class($shadowError) . " in dibi 5: {$shadowError->getMessage()}",
				['site' => $site, 'call' => $method],
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


	/**
	 * Instant and timezone of $source, computed once per mutation for all aliases.
	 * @return array{\DateTimeZone, int[], \DateTimeZone}
	 */
	private static function nativeState(self $source): array
	{
		$state = \DateTimeImmutable::createFromMutable($source);
		$utc = $state->setTimezone(new \DateTimeZone('UTC')); // UTC avoids DST ambiguity
		$parts = array_map('intval', explode(' ', $utc->format('Y n j G i s u'))); // no string parsing, works for years > 9999
		return [$utc->getTimezone(), $parts, $state->getTimezone()];
	}


	/**
	 * Copies state from nativeState() without calling overridden methods.
	 * @param  array{\DateTimeZone, int[], \DateTimeZone}  $state
	 */
	private function copyNativeState(array $state): void
	{
		[$utc, [$y, $m, $d, $h, $i, $s, $u], $zone] = $state;
		parent::setTimezone($utc);
		parent::setDate($y, $m, $d);
		parent::setTime($h, $i, $s, $u);
		parent::setTimezone($zone);
	}


	/** @param  string  $realState  STATE_FORMAT of the real value, same for all aliases */
	private function refreshDivergence(string $site, string $call, string $realState): void
	{
		if ($realState === $this->shadowState()) {
			$this->divergedBy = [];
		} elseif (!isset($this->divergedBy[$site]) && count($this->divergedBy) < 5) {
			$this->divergedBy[$site] = $call;
		}
	}


	/**
	 * Shadow of a numeric (or coerced) constructor argument, dibi 5 converts the timestamp
	 * through local time, which shifts DST-ambiguous hours and fails for years > 9999.
	 */
	private function initDibi5Construct(string|int $time, ?\DateTimeZone $timezone, ?string $site): void
	{
		$call = 'new Dibi\DateTime(' . self::describe($time) . ')';
		$site ??= Reporter::callSite();
		try {
			if (is_numeric($time)) {
				$timezone ??= new \DateTimeZone(date_default_timezone_get());
				$tmp = (new \DateTimeImmutable('@' . $time))->setTimezone($timezone);
				$dibi5 = new \DateTimeImmutable($tmp->format('Y-m-d H:i:s.u'), $tmp->getTimezone());
			} else {
				$dibi5 = new \DateTimeImmutable($time, $timezone);
			}
		} catch (\Throwable $e) {
			Reporter::report(
				Reporter::WOULD_THROW,
				"$call at $site throws " . get_class($e) . " in dibi 5: {$e->getMessage()}",
				['site' => $site, 'call' => 'new'],
			);
			return;
		}
		if ($dibi5->format(self::STATE_FORMAT) !== $this->shadowState()) {
			$this->shadow = $dibi5;
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

		$causes = $this->divergedBy + ($other ? $other->divergedBy : []);
		$site = Reporter::callSite();
		Reporter::report(
			Reporter::DIVERGED_READ,
			"$call at $site returns " . self::describe($real) . ", in dibi 5 it returns $expected. Caused by "
				. ($causes
					? implode(', ', array_map(fn($site, $call) => "$call at $site", array_keys($causes), $causes))
					: 'an untracked call (procedural date_*() function?)')
				. '.',
			['site' => $site, 'call' => strstr($call, '(', true), 'mutations' => implode(';', array_keys($causes))],
		);
	}


	private function isInSync(): bool
	{
		return date_format($this, self::STATE_FORMAT) === $this->shadowState();
	}


	private function shadow(): \DateTimeImmutable
	{
		// objects created without constructor (__set_state(), subclass factories, ...) are initialized lazily
		return $this->shadow ??= \DateTimeImmutable::createFromMutable($this);
	}


	private function shadowState(): string
	{
		if ($this->shadowStateOf !== $this->shadow()) {
			$this->shadowStateOf = $this->shadow;
			$this->shadowState = $this->shadow->format(self::STATE_FORMAT);
		}
		return $this->shadowState;
	}


	private function usage(): Usage
	{
		return $this->usage ??= new Usage;
	}


	private static function track(mixed $object): mixed
	{
		if ($object instanceof self) { // shadow must exist before procedural date_*() functions can touch the object
			$object->shadow = \DateTimeImmutable::createFromMutable($object);
		}
		return $object;
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


	private static function withoutShimState(array $data): array
	{
		$prefix = "\0" . self::class . "\0";
		return array_filter($data, fn($key) => !str_starts_with((string) $key, $prefix), ARRAY_FILTER_USE_KEY);
	}


	private static function describe(mixed $value): string
	{
		return match (true) {
			$value instanceof \DateTimeZone => $value->getName(),
			$value instanceof \DateInterval => $value->format('%RP%yY%mM%dDT%hH%iM%sS.%F (days: %a)'),
			$value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)->format(self::STATE_FORMAT),
			is_array($value) => (string) json_encode($value),
			is_object($value) => get_class($value),
			default => var_export($value, true),
		};
	}
}
