<?php

declare(strict_types=1);

namespace Tests;

use Dibi\DateTime;
use Dibi\DateTimeMigration\Reporter;
use PHPUnit\Framework\TestCase;


final class DateTimeTest extends TestCase
{
	/** @var list<array{kind: string, message: string}> */
	private array $reports = [];


	protected function setUp(): void
	{
		date_default_timezone_set('Europe/Prague');
		Reporter::reset();
		Reporter::$enabled = true;
		Reporter::$reportDiscarded = true;
		Reporter::$handler = function (string $message, string $kind): void {
			$this->reports[] = ['kind' => $kind, 'message' => $message];
		};
	}


	protected function tearDown(): void
	{
		Reporter::$handler = null;
	}


	private function kinds(): array
	{
		gc_collect_cycles();
		return array_column($this->reports, 'kind');
	}


	public function testShimIsLoaded(): void
	{
		$this->assertTrue(DateTime::MIGRATION_SHIM);
		$this->assertStringContainsString('dibi-datetime-migration/src', (new \ReflectionClass(DateTime::class))->getFileName());
	}


	public function testMutableUsageIsReported(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 day');
		$this->assertSame('2026-10-18', $date->format('Y-m-d')); // dibi 3 value is kept

		$this->assertSame([Reporter::DISCARDED_RESULT, Reporter::DIVERGED_READ], $this->kinds());
		$this->assertStringContainsString("format('Y-m-d') at " . __FILE__, $this->reports[1]['message']);
		$this->assertStringContainsString("returns '2026-10-18', in dibi 5 it returns '2026-10-17'", $this->reports[1]['message']);
		$this->assertStringContainsString("modify('+1 day') at " . __FILE__ . ':' . (__LINE__ - 6), $this->reports[1]['message']);
	}


	public function testImmutableUsageIsSilent(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$plusDay = $date->modify('+1 day');
		$this->assertSame('2026-10-18', $plusDay->format('Y-m-d'));
		$this->assertSame([], $this->kinds());
	}


	public function testOriginalReadAfterImmutableStyleMutationIsReported(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$plusDay = $date->modify('+1 day');
		$this->assertSame('2026-10-18', $plusDay->format('Y-m-d'));
		$this->assertSame('2026-10-18', $date->format('Y-m-d')); // relies on mutation
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds());
	}


	public function testReadNotAffectedByMutationIsSilent(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 hour');
		$this->assertSame('2026-10-17', $date->format('Y-m-d'));
		$this->assertSame([Reporter::DISCARDED_RESULT], $this->kinds());
	}


	public function testDiscardedReportCanBeDisabled(): void
	{
		Reporter::$reportDiscarded = false;
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 hour');
		$this->assertSame([], $this->kinds());
	}


	public function testChainedCallsAndReassignmentAreSilent(): void
	{
		$date = new DateTime('2026-10-17 12:34:56');
		$this->assertSame('2026-10-18 00:00:00', $date->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'));

		$date = new DateTime('2026-10-17 12:34:56');
		$date = $date->modify('+1 day');
		$date = $date->setDate(2027, 1, 1);
		$this->assertSame('2027-01-01 12:34:56', $date->format('Y-m-d H:i:s'));
		$this->assertSame([], $this->kinds());
	}


	public function testAllAliasesShareStateLikeDibi3(): void
	{
		$a = new DateTime('2026-10-17 12:00:00');
		$b = $a->modify('+1 day');
		$c = $b->modify('+1 day');
		$a->modify('+1 day'); // mutating stale alias

		Reporter::$enabled = false;
		$this->assertSame('2026-10-20', $a->format('Y-m-d'));
		$this->assertSame('2026-10-20', $b->format('Y-m-d'));
		$this->assertSame('2026-10-20', $c->format('Y-m-d'));
	}


	public function testStaleAliasLineage(): void
	{
		$a = new DateTime('2026-10-17 12:00:00');
		$a->modify('+1 day'); // discarded
		$b = $a->modify('+1 day'); // dibi 5: 2026-10-18, dibi 3: 2026-10-19
		$this->assertSame('2026-10-19', $b->format('Y-m-d'));
		$this->assertStringContainsString("in dibi 5 it returns '2026-10-18'", end($this->reports)['message']);
	}


	public function testParameterMutation(): void
	{
		$normalize = function (\DateTime $d): void {
			$d->setTime(0, 0);
		};
		$date = new DateTime('2026-10-17 12:34:56');
		$normalize($date);
		$this->assertSame('2026-10-17 00:00:00', (string) $date->format('Y-m-d H:i:s'));
		$this->assertContains(Reporter::DIVERGED_READ, $this->kinds());
	}


	public function testCloneIsIndependent(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$copy = clone $date;
		$copy = $copy->modify('+1 day');
		$this->assertSame('2026-10-17', $date->format('Y-m-d'));
		$this->assertSame('2026-10-18', $copy->format('Y-m-d'));
		$this->assertSame([], $this->kinds());
	}


	public function testCloneOfDivergedKeepsDivergence(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 day');
		$copy = clone $date;
		$this->assertSame('2026-10-18', $copy->format('Y-m-d'));
		$this->assertContains(Reporter::DIVERGED_READ, $this->kinds());
	}


	public function testModifyCloneIsReportedAsRemoved(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$next = $date->modifyClone('+1 day');
		$this->assertSame('2026-10-18', $next->format('Y-m-d'));
		$this->assertSame('2026-10-17', $date->format('Y-m-d'));
		$this->assertSame([Reporter::REMOVED_API], $this->kinds());
	}


	public function testAllMutators(): void
	{
		$cases = [
			'add' => fn(DateTime $d) => $d->add(new \DateInterval('P1D')),
			'sub' => fn(DateTime $d) => $d->sub(new \DateInterval('PT1H')),
			'setTimezone' => fn(DateTime $d) => $d->setTimezone(new \DateTimeZone('UTC')),
			'setTime' => fn(DateTime $d) => $d->setTime(1, 2, 3, 4),
			'setDate' => fn(DateTime $d) => $d->setDate(2000, 1, 1),
			'setISODate' => fn(DateTime $d) => $d->setISODate(2000, 10),
			'setTimestamp' => fn(DateTime $d) => $d->setTimestamp(0),
			'setMicrosecond' => fn(DateTime $d) => $d->setMicrosecond(5),
		];
		foreach ($cases as $name => $mutate) {
			$this->reports = [];
			Reporter::reset();

			$immutable = $mutate(new DateTime('2026-10-17 12:00:00'));
			$this->assertInstanceOf(DateTime::class, $immutable);
			$immutable->format('c');
			$this->assertSame([], $this->kinds(), $name);

			$date = new DateTime('2026-10-17 12:00:00');
			$mutate($date);
			$date->format('Y-m-d H:i:s.u e');
			$this->assertContains(Reporter::DIVERGED_READ, $this->kinds(), $name);
		}
	}


	public function testOtherReaders(): void
	{
		Reporter::$reportDiscarded = false;
		$date = new DateTime('2026-10-17 12:00:00');
		$date->setTimezone(new \DateTimeZone('UTC'));

		$date->getTimezone();
		$date->getOffset();
		$date->getTimestamp(); // same instant, must not be reported
		(string) $date;
		$this->assertCount(3, $this->kinds());
	}


	public function testDiffUsesShadowOfBothSides(): void
	{
		$start = new DateTime('2026-10-17 12:00:00');
		$end = new DateTime('2026-10-17 12:00:00');
		Reporter::$reportDiscarded = false;
		$end->modify('+2 days');

		$this->assertSame(2, $start->diff($end)->days);
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds());
	}


	public function testRelativeNowDoesNotDrift(): void
	{
		$date = new DateTime;
		$date = $date->modify('now');
		$date = $date->modify('+1 sec');
		$date->format('Y-m-d H:i:s.u');
		$this->assertSame([], $this->kinds());
	}


	public function testDstAmbiguousHourIsCopiedExactly(): void
	{
		// 2026-10-25 02:30 happens twice in Europe/Prague
		$date = new DateTime('2026-10-25 00:30:00 UTC');
		$date = $date->setTimezone(new \DateTimeZone('Europe/Prague'));
		$alias = $date->modify('+0 sec');
		$alias->setTimestamp($date->getTimestamp() + 3600); // CET 02:30, second occurrence

		Reporter::$enabled = false;
		$this->assertSame($alias->format('U P'), $date->format('U P'));
		$this->assertSame('02:30:00 +01:00', $date->format('H:i:s P'));
	}


	public function testSerializationKeepsNativeFormat(): void
	{
		$date = new DateTime('2026-10-17 12:00:00.123456');
		$serialized = serialize($date);
		$this->assertStringNotContainsString('shadow', $serialized);
		$this->assertSame(
			serialize(new \DateTime('2026-10-17 12:00:00.123456')),
			str_replace('O:13:"Dibi\DateTime"', 'O:8:"DateTime"', $serialized),
		);

		$restored = unserialize($serialized);
		$this->assertInstanceOf(DateTime::class, $restored);
		$this->assertSame('2026-10-17 12:00:00.123456', (string) $restored);

		$restored = $restored->modify('+1 day');
		$this->assertSame('2026-10-18', $restored->format('Y-m-d'));
		$this->assertSame([], $this->kinds());
	}


	public function testCreateFromFormatIsTrackedLazily(): void
	{
		$date = DateTime::createFromFormat('Y-m-d H:i:s', '2026-10-17 12:00:00');
		$this->assertInstanceOf(DateTime::class, $date);
		$date->modify('+1 day');
		$date->format('Y-m-d');
		$this->assertContains(Reporter::DIVERGED_READ, $this->kinds());
	}


	public function testProceduralMutationIsDetectedAsUntracked(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		date_modify($date, '+1 day');
		$date->format('Y-m-d');
		$this->assertStringContainsString('untracked call', $this->reports[0]['message']);
	}


	public function testNullConstructorArgument(): void
	{
		new DateTime(null);
		$this->assertSame([Reporter::WOULD_THROW], $this->kinds());
	}


	public function testDeduplication(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$date = new DateTime('2026-10-17 12:00:00');
			$date->modify("+$i day");
			$date->modify('+1 day')->format('Y-m-d');
		}
		$this->assertSame([Reporter::DISCARDED_RESULT, Reporter::DIVERGED_READ], $this->kinds());
	}


	public function testNumericConstructor(): void
	{
		$date = new DateTime(0);
		$this->assertSame('1970-01-01 01:00:00 Europe/Prague', $date->format('Y-m-d H:i:s e'));
		$this->assertSame([], $this->kinds());
	}


	public function testDisabledReporterKeepsPlainDibi3Behaviour(): void
	{
		Reporter::$enabled = false;
		$date = new DateTime('2026-10-17 12:00:00');
		$this->assertSame($date, $date->modify('+1 day'));
		$this->assertSame('2026-10-18', $date->format('Y-m-d'));
	}


	public function testDisablingKeepsAliasesInSync(): void
	{
		$a = new DateTime('2026-10-17 12:00:00');
		$b = $a->modify('+1 day');
		Reporter::$enabled = false;
		$a->modify('+1 day');
		$this->assertSame('2026-10-19', $b->format('Y-m-d'));
	}


	public function testYearsAboveFourDigits(): void
	{
		$a = new DateTime('2026-10-17 12:00:00');
		$b = $a->setDate(12000, 1, 1);
		$c = $b->modify('+1 day');
		Reporter::$enabled = false;
		$this->assertSame('12000-01-02 12:00:00', $a->format('Y-m-d H:i:s'));
		$this->assertSame('12000-01-02 12:00:00', $c->format('Y-m-d H:i:s'));
	}


	public function testFractionalTimestampLikeDibi3(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$date = $date->setTimestamp('1700000000.5');
		$this->assertSame('1700000000.500000 Europe/Prague', $date->format('U.u e'));
		$this->assertSame([Reporter::WOULD_THROW], $this->kinds());
	}


	public function testSubclassPropertiesSurviveSerialization(): void
	{
		$date = new SubDateTime('2026-10-17 12:00:00');
		$date->pub = 'x';
		$date->setProt('y');
		$date = $date->modify('+1 day');
		$restored = unserialize(serialize($date));
		$this->assertSame('x', $restored->pub);
		$this->assertSame('y', $restored->getProt());
		$this->assertSame('2026-10-18', $restored->format('Y-m-d'));
		$this->assertStringNotContainsString('shadow', serialize($date));
	}


	public function testThrowingSinkDoesNotBreakDibi3Code(): void
	{
		Reporter::$handler = fn() => throw new \ErrorException('converted');
		$date = new DateTime('2026-10-17 12:00:00');
		$alias = $date->modify('+1 day');
		$date->modify('+1 day');
		$this->assertSame('2026-10-19', @$date->format('Y-m-d'));
		$this->assertSame('2026-10-19', $alias->format('Y-m-d'));
	}


	public function testRethrowRaisesEveryTime(): void
	{
		Reporter::$rethrow = true;
		Reporter::$reportDiscarded = false;
		Reporter::$handler = fn() => throw new \ErrorException('converted');
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 day');
		for ($i = 0; $i < 2; $i++) {
			try {
				$date->format('Y-m-d');
				$this->fail('not thrown');
			} catch (\ErrorException) {
			}
		}
		Reporter::$rethrow = false;
		$this->assertTrue(true);
	}


	public function testMagicPropertyIsReported(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$this->assertSame($date->getTimestamp(), $date->timestamp);
		$this->assertSame([Reporter::REMOVED_API], $this->kinds());
		$this->assertStringContainsString('$date->timestamp at ' . __FILE__ . ':' . (__LINE__ - 2), $this->reports[0]['message']);
	}


	public function testExtensionMethodIsReported(): void
	{
		DateTime::extensionMethod('shimTestTomorrow', fn(DateTime $d) => $d->modifyClone('+1 day'));
		$this->assertSame([Reporter::REMOVED_API], $this->kinds());

		$this->reports = [];
		$date = new DateTime('2026-10-17 12:00:00');
		$this->assertSame('2026-10-18', $date->shimTestTomorrow()->format('Y-m-d'));
		$this->assertStringContainsString('shimTestTomorrow() at ' . __FILE__, $this->reports[0]['message']);
	}


	public function testUndefinedMethodStillThrows(): void
	{
		$this->expectException(\LogicException::class);
		(new DateTime)->undefinedMethod();
	}


	public function testCreateFromImmutableIsReported(): void
	{
		$date = DateTime::createFromImmutable(new \DateTimeImmutable('2026-10-17 12:00:00'));
		$this->assertInstanceOf(DateTime::class, $date);
		$this->assertSame([Reporter::REMOVED_API], $this->kinds());
	}


	public function testFactoriesTrackStateEagerly(): void
	{
		$dates = [
			DateTime::createFromFormat('Y-m-d H:i:s', '2026-10-17 12:00:00'),
			DateTime::createFromInterface(new \DateTimeImmutable('2026-10-17 12:00:00')),
		];
		foreach ($dates as $date) {
			date_modify($date, '+1 day');
			$this->assertSame('2026-10-18', $date->format('Y-m-d'));
		}
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds()); // same site, deduplicated
		$this->assertStringContainsString('untracked call', $this->reports[0]['message']);
	}


	public function testNumericConstructorInDstFoldDiffersInDibi5(): void
	{
		$timestamp = (new \DateTimeImmutable('2026-10-25 00:30:00 UTC'))->getTimestamp(); // 02:30 CEST, first occurrence
		$date = new DateTime($timestamp);
		$this->assertSame('02:30 +02:00', $date->format('H:i P')); // dibi 3 value
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds());
		$this->assertStringContainsString("in dibi 5 it returns '02:30 +01:00'", $this->reports[0]['message']);
		$this->assertStringContainsString("new Dibi\\DateTime($timestamp) at " . __FILE__ . ':' . (__LINE__ - 4), $this->reports[0]['message']);

		$this->reports = [];
		$date->format('Y-m-d H:i'); // same in both versions
		$this->assertSame([], $this->kinds());
	}


	public function testNumericConstructorAboveYear9999ThrowsInDibi5(): void
	{
		$date = new DateTime(253402300800);
		$this->assertSame('10000-01-01', $date->format('Y-m-d'));
		$this->assertSame([Reporter::WOULD_THROW], $this->kinds());
	}


	public function testNonScalarConstructorArgumentInStrictFile(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = new DateTime(new Fixtures\Stringable);
		$this->assertSame('2020-01-01 10:00:00', $date->format('Y-m-d H:i:s')); // works like dibi 3
		$date = new DateTime(1.5);
		$this->assertSame('1970-01-01 01:00:01.500000', (string) $date);
		$this->assertSame([Reporter::WOULD_THROW, Reporter::WOULD_THROW], $this->kinds());
	}


	public function testConstructorCoercionInNonStrictFile(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = Fixtures\newDateTime(new Fixtures\Stringable);
		$this->assertSame('2020-01-01 10:00:00', $date->format('Y-m-d H:i:s'));
		$this->assertSame([], $this->kinds());

		$date = Fixtures\newDateTime(false); // dibi 3: now, dibi 5: 0 = 1970-01-01
		$this->assertSame(date('Y'), $date->format('Y'));
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds());
		$this->assertStringContainsString("in dibi 5 it returns '1970'", $this->reports[0]['message']);
	}


	public function testSetTimestampCoercionInNonStrictFile(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = Fixtures\setTimestamp(new DateTime('2026-10-17 12:00:00'), '1700000000');
		$date->format('Y-m-d H:i:s.u');
		$this->assertSame([], $this->kinds());

		$date = Fixtures\setTimestamp(new DateTime('2026-10-17 12:00:00'), '1700000000.5');
		$date->format('Y-m-d H:i:s'); // fraction is not read
		$this->assertSame([], $this->kinds());
		$this->assertSame('500000', $date->format('u'));
		$this->assertSame([Reporter::DIVERGED_READ], $this->kinds());
	}


	public function testSetTimestampBoolInNonStrictFile(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = Fixtures\setTimestamp(new DateTime('2026-10-17 12:00:00'), true);
		$this->assertSame('1970-01-01 01:00:01', $date->format('Y-m-d H:i:s'));
		$this->assertSame([], $this->kinds());
	}


	public function testSubclassCloneWithoutParentCallIsIndependent(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = new Fixtures\CloningSubDateTime('2026-10-17 12:00:00');
		$date->modify('+1 day');
		$copy = clone $date;
		$copy->modify('+5 day');
		Reporter::$enabled = false;
		$this->assertSame('2026-10-18', $date->format('Y-m-d'));
		$this->assertSame('2026-10-23', $copy->format('Y-m-d'));
	}


	public function testSetTimestampNumericStringInStrictFile(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$date = $date->setTimestamp('1700000000');
		$this->assertSame([Reporter::WOULD_THROW], $this->kinds());
	}


	public function testSubclassWithoutReturnTypesCompiles(): void
	{
		require_once __DIR__ . '/fixtures/coercive.php';
		$date = new Fixtures\UntypedSubDateTime('2026-10-17 12:00:00');
		$this->assertSame('2026-10-17', $date->format('Y-m-d'));
	}


	public function testCloneOfResultIsUsage(): void
	{
		$date = new DateTime('2026-10-17 12:00:00');
		$copy = clone $date->setTime(5, 0);
		$this->assertSame('05:00', $copy->format('H:i'));
		$this->assertNotContains(Reporter::DISCARDED_RESULT, $this->kinds());
	}


	public function testNoOpMutationIsNotDiscarded(): void
	{
		$date = new DateTime('2026-10-17 00:00:00');
		$date->setTime(0, 0);
		$date->setTimezone(new \DateTimeZone('Europe/Prague'));
		$this->assertSame('2026-10-17 00:00', $date->format('Y-m-d H:i'));
		$this->assertSame([], $this->kinds());
	}


	public function testDifferentReadsOnSameLineAreReportedSeparately(): void
	{
		Reporter::$reportDiscarded = false;
		$date = new DateTime('2026-10-17 12:00:00');
		$date->modify('+1 day');
		[$date->format('Y-m-d'), $date->getTimestamp()];
		$this->assertSame([Reporter::DIVERGED_READ, Reporter::DIVERGED_READ], $this->kinds());
	}


	public function testDebugInfoShowsSubclassProperties(): void
	{
		$date = new SubDateTime('2026-10-17 12:00:00');
		$date->pub = 'x';
		$dump = print_r($date, true);
		$this->assertStringContainsString('[pub] => x', $dump);
		$this->assertStringContainsString('[date] => 2026-10-17 12:00:00.000000', $dump);
		$this->assertStringNotContainsString('shadow', $dump);
	}
}


class SubDateTime extends DateTime
{
	public $pub;
	protected $prot;


	public function setProt($value): void
	{
		$this->prot = $value;
	}


	public function getProt()
	{
		return $this->prot;
	}
}
