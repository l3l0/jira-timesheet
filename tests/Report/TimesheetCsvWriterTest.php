<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Report;

use JiraTimesheet\Report\TimesheetCsvWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimesheetCsvWriterTest extends TestCase
{
    #[Test]
    public function it_writes_timesheet_rows_to_csv(): void
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'timesheet-report-');

        (new TimesheetCsvWriter())->write($path, [
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
            "date,row_type,issue_key,summary,hours,seconds\n"
            . "2026-05-21,task,PAR-1,\"Long task\",14.00,50400\n"
            . "2026-05-21,total,,TOTAL,16.00,57600\n",
            (string) \file_get_contents($path),
        );
    }
}
