<?php

declare(strict_types=1);

namespace JiraTimesheet\Report;

use function Flow\ETL\DSL\data_frame;
use function Flow\ETL\DSL\from_array;
use function Flow\ETL\DSL\ref;
use function Flow\ETL\DSL\sum;
use function Flow\ETL\DSL\to_array;

use JiraTimesheet\Jira\WorklogEntry;

final class TimesheetAggregator
{
    /**
     * @param iterable<WorklogEntry> $entries
     * @return list<array{date:string,row_type:string,issue_key:string,summary:string,hours:string,seconds:int}>
     */
    public function aggregate(iterable $entries): array
    {
        $inputRows = $this->entryRows($entries);
        /** @var list<array<int|string, mixed>> $taskRows */
        $taskRows = [];
        $this->runFlow(static function () use ($inputRows, &$taskRows): void {
            data_frame()
                ->read(from_array($inputRows))
                ->groupBy(ref('date'), ref('issue_key'), ref('summary'))
                ->aggregate(sum(ref('seconds')))
                ->write(to_array($taskRows))
                ->run();
        });

        if ($taskRows === []) {
            return [];
        }

        /** @var list<array<int|string, mixed>> $taskRows */
        $taskRows = \array_values($taskRows);
        $totalRows = $this->totalRows($taskRows);

        \usort(
            $taskRows,
            static fn (array $left, array $right): int => [$left['date'], $left['issue_key']]
                <=> [$right['date'], $right['issue_key']],
        );
        \usort(
            $totalRows,
            static fn (array $left, array $right): int => $left['date'] <=> $right['date'],
        );

        $tasksByDate = [];

        foreach ($taskRows as $row) {
            $date = $this->stringField($row, 'date');

            $tasksByDate[$date][] = $this->reportRow(
                $date,
                'task',
                $this->stringField($row, 'issue_key'),
                $this->stringField($row, 'summary'),
                $this->intField($row, 'seconds_sum'),
            );
        }

        $reportRows = [];

        foreach ($totalRows as $row) {
            $date = $this->stringField($row, 'date');

            foreach ($tasksByDate[$date] ?? [] as $taskRow) {
                $reportRows[] = $taskRow;
            }

            $reportRows[] = $this->reportRow(
                $date,
                'total',
                '',
                'TOTAL',
                $this->intField($row, 'seconds_sum'),
            );
        }

        return $reportRows;
    }

    /**
     * @param iterable<WorklogEntry> $entries
     * @return \Generator<int, array{date:string, issue_key:string, summary:string, seconds:int}>
     */
    private function entryRows(iterable $entries): \Generator
    {
        foreach ($entries as $entry) {
            yield [
                'date' => $entry->date,
                'issue_key' => $entry->issueKey,
                'summary' => $entry->summary,
                'seconds' => $entry->seconds,
            ];
        }
    }

    /**
     * @param list<array<int|string, mixed>> $taskRows
     * @return list<array{date:string, seconds_sum:int}>
     */
    private function totalRows(array $taskRows): array
    {
        $secondsByDate = [];

        foreach ($taskRows as $row) {
            $date = $this->stringField($row, 'date');
            $secondsByDate[$date] = ($secondsByDate[$date] ?? 0) + $this->intField($row, 'seconds_sum');
        }

        \ksort($secondsByDate);

        $totalRows = [];

        foreach ($secondsByDate as $date => $seconds) {
            $totalRows[] = [
                'date' => $date,
                'seconds_sum' => $seconds,
            ];
        }

        return $totalRows;
    }

    /**
     * @return array{date:string,row_type:string,issue_key:string,summary:string,hours:string,seconds:int}
     */
    private function reportRow(string $date, string $rowType, string $issueKey, string $summary, int $seconds): array
    {
        return [
            'date' => $date,
            'row_type' => $rowType,
            'issue_key' => $issueKey,
            'summary' => $summary,
            'hours' => \number_format(\round($seconds / 3600, 2, \PHP_ROUND_HALF_UP), 2, '.', ''),
            'seconds' => $seconds,
        ];
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function intField(array $row, string $field): int
    {
        $value = $row[$field] ?? 0;

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function runFlow(callable $operation): void
    {
        \set_error_handler(static function (int $severity, string $message, string $file): bool {
            if (($severity & \E_DEPRECATED) !== 0 && \str_contains($file, '/vendor/flow-php/')) {
                return true;
            }

            return false;
        });

        try {
            $operation();
        } finally {
            \restore_error_handler();
        }
    }
}
