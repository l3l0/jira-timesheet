<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Report;

use JiraTimesheet\Jira\WorklogEntry;
use JiraTimesheet\Report\TimesheetAggregator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimesheetAggregatorTest extends TestCase
{
    #[Test]
    public function it_groups_reference_entries_by_day_and_task_with_daily_total(): void
    {
        $rows = (new TimesheetAggregator())->aggregate([
            new WorklogEntry('2026-05-21', 'PAR-2', 'Short task', 1800),
            new WorklogEntry('2026-05-21', 'PAR-2', 'Short task', 1800),
            new WorklogEntry('2026-05-21', 'PAR-2', 'Short task', 1800),
            new WorklogEntry('2026-05-21', 'PAR-2', 'Short task', 1800),
            new WorklogEntry('2026-05-21', 'PAR-1', 'Long task', 25200),
            new WorklogEntry('2026-05-21', 'PAR-1', 'Long task', 25200),
        ]);

        self::assertSame([
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-1',
                'summary' => 'Long task',
                'hours' => '14.00',
                'seconds' => 50400,
            ],
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-2',
                'summary' => 'Short task',
                'hours' => '2.00',
                'seconds' => 7200,
            ],
            [
                'date' => '2026-05-21',
                'row_type' => 'total',
                'issue_key' => '',
                'summary' => 'TOTAL',
                'hours' => '16.00',
                'seconds' => 57600,
            ],
        ], $rows);
    }

    #[Test]
    public function it_groups_multiple_days_and_keeps_totals_at_the_end_of_each_day(): void
    {
        $rows = (new TimesheetAggregator())->aggregate([
            new WorklogEntry('2026-05-22', 'PAR-3', 'Third task', 3600),
            new WorklogEntry('2026-05-21', 'PAR-2', 'Second task', 7200),
            new WorklogEntry('2026-05-21', 'PAR-1', 'First task', 1800),
            new WorklogEntry('2026-05-22', 'PAR-1', 'First task', 5400),
        ]);

        self::assertSame([
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-1',
                'summary' => 'First task',
                'hours' => '0.50',
                'seconds' => 1800,
            ],
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-2',
                'summary' => 'Second task',
                'hours' => '2.00',
                'seconds' => 7200,
            ],
            [
                'date' => '2026-05-21',
                'row_type' => 'total',
                'issue_key' => '',
                'summary' => 'TOTAL',
                'hours' => '2.50',
                'seconds' => 9000,
            ],
            [
                'date' => '2026-05-22',
                'row_type' => 'task',
                'issue_key' => 'PAR-1',
                'summary' => 'First task',
                'hours' => '1.50',
                'seconds' => 5400,
            ],
            [
                'date' => '2026-05-22',
                'row_type' => 'task',
                'issue_key' => 'PAR-3',
                'summary' => 'Third task',
                'hours' => '1.00',
                'seconds' => 3600,
            ],
            [
                'date' => '2026-05-22',
                'row_type' => 'total',
                'issue_key' => '',
                'summary' => 'TOTAL',
                'hours' => '2.50',
                'seconds' => 9000,
            ],
        ], $rows);
    }

    #[Test]
    public function it_accepts_iterable_entries_without_requiring_a_list(): void
    {
        $rows = (new TimesheetAggregator())->aggregate($this->iterableEntries());

        self::assertSame([
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-1',
                'summary' => 'Generated task',
                'hours' => '2.00',
                'seconds' => 7200,
            ],
            [
                'date' => '2026-05-21',
                'row_type' => 'total',
                'issue_key' => '',
                'summary' => 'TOTAL',
                'hours' => '2.00',
                'seconds' => 7200,
            ],
        ], $rows);
    }

    /**
     * @return \Generator<int, WorklogEntry>
     */
    private function iterableEntries(): \Generator
    {
        yield new WorklogEntry('2026-05-21', 'PAR-1', 'Generated task', 3600);
        yield new WorklogEntry('2026-05-21', 'PAR-1', 'Generated task', 3600);
    }
}
