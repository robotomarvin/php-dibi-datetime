# dibi DateTime migration shim

Temporary replacement of dibi 3 `Dibi\DateTime` for the upgrade to dibi 4/5, where `Dibi\DateTime`
becomes immutable. The app keeps dibi 3 behaviour (data stays correct), and every place
where dibi 5 would return a different value gets reported.

```php
$date = $row->createdAt;
$date->modify('+1 day');
return $date->format('Y-m-d');   // reported: returns '2026-10-18', in dibi 5 it returns '2026-10-17'

$date = $row->createdAt;
$plusDay = $date->modify('+1 day');
return $plusDay->format('Y-m-d'); // silent
```

## How it works

Every object holds its real (mutable, dibi 3) state plus a *shadow* `DateTimeImmutable`
with the value the same variable would have in dibi 5.

- Mutators (`modify`, `add`, `sub`, `setDate`, `setISODate`, `setTime`, `setTimestamp`,
  `setTimezone`, `setMicrosecond`) return a **new** object. The receiver and all its aliases
  (objects that would be one object in dibi 3) are still changed in place, so dibi 3 code works as before.
  The returned object gets the dibi 5 result as its shadow.
- Readers (`format`, `getTimestamp`, `getOffset`, `getTimezone`, `getMicrosecond`, `diff`,
  `__toString`, `serialize`) compare the real result with the shadow result and report
  **only when they differ**. A read that is not affected by the mutation (`format('Y')` after
  `modify('+1 hour')`) is not reported.

Report kinds (`Reporter::*`):

| kind | meaning | confidence |
|---|---|---|
| `diverged-read` | value read differs in dibi 5; message has read site + causing call sites | exact |
| `discarded-result` | object returned by a mutator was never used (and the call changed the value) | possible, see below |
| `removed-api` | API missing in dibi 5, see below | exact |
| `would-throw` | call throws in dibi 5, see below | exact |

`removed-api` covers `modifyClone()`, static `createFromImmutable()` (dibi 5 extends
`DateTimeImmutable`, use `createFromInterface()`) and the dibi 3 `Strict` trait: magic properties
(`$date->timestamp`, `$date->timezone`, `$date->offset`, ... are `null` with a warning in dibi 5),
`extensionMethod()` registrations and calls of extension methods.

`would-throw` covers `new Dibi\DateTime(null)`, constructor arguments other than `string|int` and
non-`int` `setTimestamp()` arguments called from a file with `declare(strict_types=1)`, numeric
constructor arguments dibi 5 can't handle (year > 9999), and mutator calls that throw on `DateTimeImmutable`.
Without `strict_types` dibi 5 coerces the argument instead (`false` → `0` = 1970-01-01, `1.5` → `1`,
`'1700000000.5'` → `1700000000`); the shim models the coerced value, so the difference shows up as
`diverged-read` only where the read value really differs.

The numeric constructor (`new Dibi\DateTime($timestamp)`) is modelled exactly as well: dibi 5 converts
the timestamp through local time, so a timestamp in the first occurrence of a DST-ambiguous hour
(e.g. `2026-10-25 02:30 +02:00` in Europe/Prague) gets a different instant there (`diverged-read`).

`discarded-result` is a hint for cases the reads can't catch: an object that is only compared
(`<`, `==`), JSON-encoded, or passed to a native `\DateTime` method, `date_format()` or
`DateTimeImmutable::createFromMutable()`. It gives false positives
when the result *is* used, but only in those unhookable ways (`if ($d->modify('+1 day') > $now)`).
Disable with `Reporter::$reportDiscarded = false`.

## Installation

Add the package and exclude the original class in the **root** `composer.json`:

```json
{
	"repositories": [
		{"type": "path", "url": "../dibi-datetime-migration"}
	],
	"require": {
		"robotomarvin/dibi-datetime-migration": "*@dev"
	},
	"autoload": {
		"exclude-from-classmap": ["vendor/dibi/dibi/src/Dibi/DateTime.php"]
	}
}
```

Run `composer update robotomarvin/dibi-datetime-migration`. `src/bootstrap.php` triggers `E_USER_WARNING` when the original class
gets loaded anyway.

## Configuration

```php
use Dibi\DateTimeMigration\Reporter;

Reporter::$errorLevel = E_USER_DEPRECATED;   // default sink is trigger_error()
Reporter::$handler = fn(string $message, string $kind, array $context) => $logger->warning($message);
Reporter::$skipPaths[] = __DIR__ . '/app/Model/Base'; // directories skipped when finding the call site
Reporter::$deduplicate = true;               // each problem (kind + method + call sites) once per process
Reporter::$rethrow = false;                  // see below
Reporter::$enabled = false;                  // no tracking, mutators return $this like dibi 3
```

Set `$enabled` once at bootstrap. Aliases created while enabled are still kept in sync after
disabling, but turning it back on later gives "untracked call" reports for those objects.
Disabled shim is still not byte-for-byte the original class, see *Differences from dibi 3* below.

The shim never changes control flow: when the sink throws (error handler converting
`E_USER_DEPRECATED` to exceptions, Tracy strict mode, PHPUnit), the exception is swallowed and the
message goes to `error_log()`. Set `Reporter::$rethrow = true` in tests to make every report fail
(not deduplicated while throwing). `discarded-result` is raised from `__destruct()` and never rethrows.

## What the shim cannot catch

These need a code search / static analysis (PHPStan against dibi 5) in addition to runtime reports:

- **Type changes.** dibi 5 `Dibi\DateTime` is not `\DateTime` anymore:
  - `instanceof \DateTime` turns false
  - `\DateTime` parameter / return / property types throw `TypeError`
  - `DateTimeImmutable::createFromMutable($row->createdAt)` throws `TypeError`, use `createFromInterface()`
  - your own classes extending `Dibi\DateTime` and overriding mutators
- **Unhookable reads** of a diverged object: comparison operators `<`, `==`, `<=>`, `max()`, `min()`,
  `sort()`, `json_encode()`, `var_export()`, `(array)`, `date_format()` and other procedural functions,
  `$nativeDateTime->diff($dibiDate)`.
- **Procedural mutation** `date_modify($d, ...)` etc.: in dibi 5 a `TypeError`. Detected only on the
  next read, reported as "untracked call".
- **Shim state is visible** in `var_export()` and `(array)` (private properties). Code that
  `var_export`s rows into generated PHP files fails on `eval`/`include`. `serialize()`, `var_dump()`
  and `json_encode()` are clean.
- **Identity.** `$d->modify('+1 day') === $d` is true in dibi 3, false here and in dibi 5.
  `SplObjectStorage` / `spl_object_id()` keyed by the date.
- **Code not executed** while the shim runs.
- `Dibi\DateTime` in dibi 5 does not use the `Strict` trait: undefined methods throw `Error`
  instead of `LogicException`, writing an undeclared property creates it (deprecated) instead of throwing.
- **DatePeriod** iterating from a diverged start date uses the dibi 3 value.

Suggested searches:

```sh
grep -rnE 'instanceof \\?DateTime\b|\(\\?DateTime \$|: \\?DateTime\b|createFromMutable|modifyClone|date_(modify|add|sub|time_set|date_set|timezone_set|timestamp_set)\(' app/
```

## Differences from dibi 3

The shim keeps dibi 3 values, but it is not fully transparent:

- **Identity.** Mutators return a new object (see above).
- **Scalar arguments.** Mutators and `format()` are userland methods with the native parameter types,
  so `null` passed to `setTime()`, `format()` etc. throws `TypeError` instead of the native
  "Passing null is deprecated". Fix the call, it is deprecated in both versions.
- **Performance.** Mutating one object many times while keeping every result
  (`$slots[] = $start->modify('+1 hour')` in a loop) is O(n²): dibi 3 semantics require updating
  all aliases on every mutation. About 4 s for 3000 kept results.
- `var_export()` / `(array)` show the shim state (above).
- Subclasses overriding `__construct()` or `setTimezone()`: the shim's `setTimestamp()` calls the native
  methods directly, dibi 3 called the overridden ones.

## Running tests

```sh
composer install
vendor/bin/phpunit tests
```

Tested with dibi 3.2.4 on PHP 8.5.
