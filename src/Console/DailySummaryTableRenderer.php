<?php

declare(strict_types=1);

namespace JiraTimesheet\Console;

final class DailySummaryTableRenderer
{
    private const COLUMNS = [
        'date' => 'Date',
        'hours' => 'Hours',
        'seconds' => 'Seconds',
    ];

    /**
     * @param list<array{date:string,hours:string,seconds:int}> $rows
     */
    public function render(array $rows): string
    {
        $widths = $this->widths($rows);
        $separator = $this->separator($widths);
        $output = $separator;
        $output .= $this->row(\array_values(self::COLUMNS), $widths);
        $output .= $separator;

        foreach ($rows as $row) {
            $output .= $this->row([
                $row['date'],
                $row['hours'],
                (string) $row['seconds'],
            ], $widths);
        }

        return $output . $separator;
    }

    /**
     * @param list<array{date:string,hours:string,seconds:int}> $rows
     * @return list<int>
     */
    private function widths(array $rows): array
    {
        $widths = \array_map(
            $this->displayWidth(...),
            \array_values(self::COLUMNS),
        );

        foreach ($rows as $row) {
            foreach (\array_keys(self::COLUMNS) as $index => $key) {
                $widths[$index] = \max($widths[$index], $this->displayWidth((string) $row[$key]));
            }
        }

        return $widths;
    }

    /**
     * @param list<int> $widths
     */
    private function separator(array $widths): string
    {
        return '+' . \implode('+', \array_map(
            static fn (int $width): string => \str_repeat('-', $width + 2),
            $widths,
        )) . "+\n";
    }

    /**
     * @param list<string> $cells
     * @param list<int> $widths
     */
    private function row(array $cells, array $widths): string
    {
        $padded = [];

        foreach ($cells as $index => $cell) {
            $padded[] = ' ' . $this->padRight($cell, $widths[$index]) . ' ';
        }

        return '|' . \implode('|', $padded) . "|\n";
    }

    private function displayWidth(string $value): int
    {
        return \mb_strwidth($value, 'UTF-8');
    }

    private function padRight(string $value, int $width): string
    {
        return $value . \str_repeat(' ', \max(0, $width - $this->displayWidth($value)));
    }
}
