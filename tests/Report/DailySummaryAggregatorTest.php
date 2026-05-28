<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Report;

use JiraTimesheet\Jira\WorklogEntry;
use JiraTimesheet\Report\DailySummaryAggregator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DailySummaryAggregatorTest extends TestCase
{
    #[Test]
    public function it_groups_worklogs_by_day_and_adds_period_total(): void
    {
        $rows = (new DailySummaryAggregator())->aggregate([
            new WorklogEntry('2026-05-03', 'PAR-3', 'Third issue', 7200),
            new WorklogEntry('2026-05-02', 'PAR-1', 'First issue', 3600),
            new WorklogEntry('2026-05-02', 'PAR-2', 'Second issue', 9000),
        ]);

        self::assertSame([
            [
                'date' => '2026-05-02',
                'hours' => '3.50',
                'seconds' => 12600,
            ],
            [
                'date' => '2026-05-03',
                'hours' => '2.00',
                'seconds' => 7200,
            ],
            [
                'date' => 'TOTAL',
                'hours' => '5.50',
                'seconds' => 19800,
            ],
        ], $rows);
    }

    #[Test]
    public function it_returns_no_total_for_empty_input(): void
    {
        self::assertSame([], (new DailySummaryAggregator())->aggregate([]));
    }
}
