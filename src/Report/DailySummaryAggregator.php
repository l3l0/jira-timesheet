<?php

declare(strict_types=1);

namespace JiraTimesheet\Report;

use JiraTimesheet\Jira\WorklogEntry;

final class DailySummaryAggregator
{
    /**
     * @param iterable<WorklogEntry> $entries
     * @return list<array{date:string,hours:string,seconds:int}>
     */
    public function aggregate(iterable $entries): array
    {
        $secondsByDate = [];

        foreach ($entries as $entry) {
            $secondsByDate[$entry->date] = ($secondsByDate[$entry->date] ?? 0) + $entry->seconds;
        }

        \ksort($secondsByDate);

        $rows = [];
        $totalSeconds = 0;

        foreach ($secondsByDate as $date => $seconds) {
            $totalSeconds += $seconds;
            $rows[] = $this->row((string) $date, $seconds);
        }

        if ($rows !== []) {
            $rows[] = $this->row('TOTAL', $totalSeconds);
        }

        return $rows;
    }

    /**
     * @return array{date:string,hours:string,seconds:int}
     */
    private function row(string $date, int $seconds): array
    {
        return [
            'date' => $date,
            'hours' => \number_format(\round($seconds / 3600, 2, \PHP_ROUND_HALF_UP), 2, '.', ''),
            'seconds' => $seconds,
        ];
    }
}
