<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Console;

use JiraTimesheet\Console\DailySummaryTableRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DailySummaryTableRendererTest extends TestCase
{
    #[Test]
    public function it_renders_daily_summary_rows_as_a_console_table(): void
    {
        $output = (new DailySummaryTableRenderer())->render([
            [
                'date' => '2026-05-02',
                'hours' => '3.50',
                'seconds' => 12600,
            ],
            [
                'date' => 'TOTAL',
                'hours' => '3.50',
                'seconds' => 12600,
            ],
        ]);

        self::assertStringContainsString('Date', $output);
        self::assertStringContainsString('Hours', $output);
        self::assertStringContainsString('Seconds', $output);
        self::assertStringContainsString('2026-05-02', $output);
        self::assertStringContainsString('TOTAL', $output);
        self::assertStringContainsString('3.50', $output);
        self::assertStringContainsString('12600', $output);
    }
}
