<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Console;

use JiraTimesheet\Console\TimesheetTableRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimesheetTableRendererTest extends TestCase
{
    #[Test]
    public function it_renders_timesheet_rows_as_an_ascii_table(): void
    {
        $table = (new TimesheetTableRenderer())->render([
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
                'row_type' => 'total',
                'issue_key' => '',
                'summary' => 'TOTAL',
                'hours' => '16.00',
                'seconds' => 57600,
            ],
        ]);

        self::assertSame(
            "+------------+----------+-----------+-----------+-------+---------+\n"
            . "| Date       | Row Type | Issue Key | Summary   | Hours | Seconds |\n"
            . "+------------+----------+-----------+-----------+-------+---------+\n"
            . "| 2026-05-21 | task     | PAR-1     | Long task | 14.00 | 50400   |\n"
            . "| 2026-05-21 | total    |           | TOTAL     | 16.00 | 57600   |\n"
            . "+------------+----------+-----------+-----------+-------+---------+\n",
            $table,
        );
    }

    #[Test]
    public function it_uses_display_width_for_polish_utf8_text(): void
    {
        $table = (new TimesheetTableRenderer())->render([
            [
                'date' => '2026-05-21',
                'row_type' => 'task',
                'issue_key' => 'PAR-7',
                'summary' => 'Łódź task',
                'hours' => '1.00',
                'seconds' => 3600,
            ],
        ]);

        self::assertSame(
            "+------------+----------+-----------+-----------+-------+---------+\n"
            . "| Date       | Row Type | Issue Key | Summary   | Hours | Seconds |\n"
            . "+------------+----------+-----------+-----------+-------+---------+\n"
            . "| 2026-05-21 | task     | PAR-7     | Łódź task | 1.00  | 3600    |\n"
            . "+------------+----------+-----------+-----------+-------+---------+\n",
            $table,
        );
    }
}
