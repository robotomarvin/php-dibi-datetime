<?php

declare(strict_types=1);

namespace Tests;

use Dibi\Connection;
use Dibi\DateTime;
use Dibi\DateTimeMigration\Reporter;
use PHPUnit\Framework\TestCase;


final class DibiIntegrationTest extends TestCase
{
	private array $reports = [];


	protected function setUp(): void
	{
		date_default_timezone_set('Europe/Prague');
		Reporter::reset();
		Reporter::$enabled = true;
		Reporter::$reportDiscarded = false;
		Reporter::$handler = function (string $message): void {
			$this->reports[] = $message;
		};
	}


	protected function tearDown(): void
	{
		Reporter::$handler = null;
		Reporter::$reportDiscarded = true;
	}


	private function connect(): Connection
	{
		$db = new Connection(['driver' => 'sqlite3', 'database' => ':memory:', 'formatDateTime' => "'Y-m-d H:i:s'"]);
		$db->query('CREATE TABLE event (id INTEGER PRIMARY KEY, created_at DATETIME)');
		$db->query('INSERT INTO event', ['created_at' => new \DateTime('2026-10-17 12:00:00')]);
		return $db;
	}


	private function fetchEvent(Connection $db): \Dibi\Row
	{
		// SQLite reports no column types, MySQL/PostgreSQL drivers detect DATETIME automatically
		return $db->query('SELECT * FROM event')->setType('created_at', \Dibi\Type::DATETIME)->fetch();
	}


	public function testRowValueIsShim(): void
	{
		$row = $this->fetchEvent($this->connect());
		$this->assertInstanceOf(DateTime::class, $row->created_at);
		$this->assertTrue($row->created_at::MIGRATION_SHIM);
	}


	public function testMutatedRowWrittenBackIsReportedWithAppCallSite(): void
	{
		$db = $this->connect();
		$row = $this->fetchEvent($db);
		$row->created_at->modify('+1 day');
		$db->query('UPDATE event SET', ['created_at' => $row->created_at], 'WHERE id = %i', $row->id);

		$this->assertSame('2026-10-18 12:00:00', $db->fetchSingle('SELECT created_at FROM event'));
		$this->assertCount(1, $this->reports);
		$this->assertStringContainsString(__FILE__ . ':' . (__LINE__ - 4) . ' (via ', $this->reports[0]);
	}


	public function testImmutableStyleIsSilent(): void
	{
		$db = $this->connect();
		$row = $this->fetchEvent($db);
		$db->query('UPDATE event SET', ['created_at' => $row->created_at->modify('+1 day')]);
		$this->assertSame('2026-10-18 12:00:00', $db->fetchSingle('SELECT created_at FROM event'));
		$this->assertSame([], $this->reports);
	}
}
