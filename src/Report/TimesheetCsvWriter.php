<?php

declare(strict_types=1);

namespace JiraTimesheet\Report;

use RuntimeException;

final class TimesheetCsvWriter
{
    private const HEADERS = ['date', 'row_type', 'issue_key', 'summary', 'hours', 'seconds'];

    /**
     * @param list<array{date:string,row_type:string,issue_key:string,summary:string,hours:string,seconds:int}> $rows
     */
    public function write(string $path, array $rows): void
    {
        $directory = \dirname($path);

        if ($directory !== '.' && !\is_dir($directory) && !@\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new RuntimeException(\sprintf('Cannot create output directory: %s', $directory));
        }

        $handle = @\fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException(\sprintf('Cannot write output CSV: %s', $path));
        }

        try {
            \fputcsv($handle, self::HEADERS, escape: '');

            foreach ($rows as $row) {
                \fputcsv($handle, [
                    $row['date'],
                    $row['row_type'],
                    $row['issue_key'],
                    $row['summary'],
                    $row['hours'],
                    $row['seconds'],
                ], escape: '');
            }
        } finally {
            \fclose($handle);
        }
    }
}
