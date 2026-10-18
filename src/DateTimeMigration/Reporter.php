<?php

declare(strict_types=1);

namespace Dibi\DateTimeMigration;


/**
 * Collects reports about Dibi\DateTime usages that behave differently in dibi 4/5 (immutable).
 */
final class Reporter
{
	/** value read from object differs from what dibi 4/5 would return */
	public const DIVERGED_READ = 'diverged-read';

	/** object returned by mutator was never used, the call is a no-op in dibi 4/5 */
	public const DISCARDED_RESULT = 'discarded-result';

	/** API removed in dibi 5 */
	public const REMOVED_API = 'removed-api';

	/** call that succeeds in dibi 3 would throw in dibi 4/5 */
	public const WOULD_THROW = 'would-throw';

	public static bool $enabled = true;

	/** report also results of mutators that were never used (lower confidence, see README) */
	public static bool $reportDiscarded = true;

	/** each distinct problem (kind + method + call sites) is reported only once per process */
	public static bool $deduplicate = true;

	public static int $errorLevel = E_USER_DEPRECATED;

	/**
	 * Exceptions thrown by the sink (error handler converting deprecations to exceptions) are
	 * sent to error_log() by default, so the shim never changes control flow of dibi 3 code.
	 */
	public static bool $rethrow = false;

	/**
	 * Custom sink, replaces trigger_error().
	 * @var (callable(string $message, string $kind, array<string, string> $context): void)|null
	 */
	public static $handler = null;

	/**
	 * Files under these directories are skipped when looking for the call site
	 * (the shim itself and dibi are added automatically).
	 * @var string[]
	 */
	public static array $skipPaths = [];

	/** @var array<string, true> */
	private static array $seen = [];


	/**
	 * @param  array<string, string>  $context
	 */
	public static function report(string $kind, string $message, array $context = []): void
	{
		if (!self::$enabled) {
			return;
		}

		$key = $kind . "\0" . implode("\0", $context);
		if (self::$deduplicate && isset(self::$seen[$key])) {
			return;
		}

		$message = 'Dibi\DateTime migration: ' . $message;
		try {
			if (self::$handler) {
				(self::$handler)($message, $kind, $context);
			} else {
				trigger_error($message, self::$errorLevel);
			}
		} catch (\Throwable $e) {
			if (self::$rethrow) {
				throw $e; // not marked as seen, so it is raised again next time
			}
			error_log($message);
		}
		self::$seen[$key] = true;
	}


	/**
	 * Returns 'file:line' of the first frame outside of the shim and dibi,
	 * with the dibi frame appended when the call went through dibi internals.
	 */
	public static function callSite(): string
	{
		$skip = self::getSkipPaths();
		$via = null;
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
			if (!isset($frame['file'])) {
				continue;
			}
			$skipped = false;
			foreach ($skip as $path) {
				if (str_starts_with($frame['file'], $path)) {
					$skipped = true;
					break;
				}
			}
			if (!$skipped) {
				return $frame['file'] . ':' . $frame['line'] . ($via ? " (via $via)" : '');
			} elseif (!str_starts_with($frame['file'], self::shimDir())) {
				$via ??= basename($frame['file']) . ':' . $frame['line'];
			}
		}
		return 'unknown';
	}


	/**
	 * Whether the file calling into the shim declares strict_types=1,
	 * i.e. whether dibi 5 scalar parameter types throw TypeError there instead of coercing.
	 */
	public static function isStrictCaller(): bool
	{
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
			if (isset($frame['file']) && !str_starts_with($frame['file'], self::shimDir())) {
				return self::isStrictFile($frame['file']);
			}
		}
		return false;
	}


	private static function isStrictFile(string $file): bool
	{
		static $cache = [];
		return $cache[$file] ??= is_file($file) && preg_match(
			'~^(?:#![^\n]*\n)?\s*<\?php(?:\s|/\*.*?\*/|//[^\n]*|#[^\n]*)*+declare\s*\((?:[^)]*,)?\s*strict_types\s*=\s*1\s*[,)]~si',
			(string) file_get_contents($file, false, null, 0, 8192),
		);
	}


	/** Forgets already reported problems (for tests). */
	public static function reset(): void
	{
		self::$seen = [];
	}


	/** @return string[] */
	private static function getSkipPaths(): array
	{
		static $dibiDir;
		$dibiDir ??= dirname((string) (new \ReflectionClass(\Dibi\Connection::class))->getFileName()) . DIRECTORY_SEPARATOR;
		return [self::shimDir(), $dibiDir, ...array_map(fn($path) => rtrim($path, '/\\') . DIRECTORY_SEPARATOR, self::$skipPaths)];
	}


	private static function shimDir(): string
	{
		return dirname(__DIR__) . DIRECTORY_SEPARATOR;
	}
}
