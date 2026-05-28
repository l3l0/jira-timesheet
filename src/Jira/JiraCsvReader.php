<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira;

use function array_map;
use function count;
use function ctype_digit;

use DateTimeImmutable;
use DateTimeZone;

use function explode;
use function Flow\ETL\Adapter\CSV\from_csv;
use function Flow\ETL\DSL\data_frame;

use Generator;

use function is_file;
use function is_readable;
use function is_scalar;
use function iterator_to_array;
use function mb_strtolower;
use function preg_match;
use function preg_quote;
use function preg_replace;

use RuntimeException;

use function sprintf;
use function str_ends_with;
use function str_pad;

use const STR_PAD_LEFT;

use function str_starts_with;

use Stringable;

use function substr;

use Throwable;

use function trim;

final class JiraCsvReader
{
    /**
     * @return list<WorklogEntry>
     * @throws Throwable
     */
    public function read(string $path): array
    {
        /** @var list<WorklogEntry> $entries */
        $entries = iterator_to_array($this->readIterable($path), preserve_keys: false);

        return $entries;
    }

    /**
     * @return Generator<int, WorklogEntry>
     * @throws Throwable
     */
    public function readIterable(string $path): Generator
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Input CSV is not readable: %s', $path));
        }

        return $this->readRows($path);
    }

    /**
     * @return Generator<int, WorklogEntry>
     * @throws Throwable
     */
    private function readRows(string $path): Generator
    {
        $headers = null;
        $issueKeyIndex = 0;
        $summaryIndex = 0;
        $worklogIndexes = [];
        $rowNumber = 0;

        foreach ($this->flowRows($path) as $row) {
            ++$rowNumber;

            if ($headers === null) {
                $headers = $this->stripBom($this->rowValues($row));
                $issueKeyIndex = $this->requiredColumnIndex($headers, 'Klucz zgłoszenia');
                $summaryIndex = $this->requiredColumnIndex($headers, 'Podsumowanie');
                $worklogIndexes = $this->worklogIndexes($headers);

                continue;
            }

            foreach ($worklogIndexes as $worklogIndex) {
                $worklog = trim($this->rowValue($row, $worklogIndex));

                if ($worklog === '') {
                    continue;
                }

                [$date, $seconds] = $this->parseWorklog($worklog, $rowNumber);

                yield new WorklogEntry(
                    $date,
                    trim($this->rowValue($row, $issueKeyIndex)),
                    trim($this->rowValue($row, $summaryIndex)),
                    $seconds,
                );
            }
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     * @throws Throwable
     */
    private function flowRows(string $path): Generator
    {
        /** @var Generator<int, array<string, mixed>> $rows */
        $rows = data_frame()
            ->read(
                from_csv($path)
                    ->withHeader(false)
                    ->withSeparator(',')
                    ->withEnclosure('"')
                    ->withEmptyToNull(false),
            )
            ->getEachAsArray();

        yield from $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function rowValues(array $row): array
    {
        $values = [];

        for ($index = 0, $count = count($row); $index < $count; ++$index) {
            $values[] = $this->rowValue($row, $index);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowValue(array $row, int $index): string
    {
        $value = $row[$this->flowColumnName($index)] ?? '';

        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }

        return (string) $value;
    }

    private function flowColumnName(int $index): string
    {
        return 'e' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<string> $headers
     * @return list<string>
     */
    private function stripBom(array $headers): array
    {
        if (isset($headers[0])) {
            $headers[0] = (string) preg_replace('/^' . preg_quote("\xEF\xBB\xBF", '/') . '/', '', (string) $headers[0]);
        }

        return array_map(
            static function ($header): string {
                $header = trim((string) $header);

                if (str_starts_with($header, '"') && str_ends_with($header, '"')) {
                    $header = substr($header, 1, -1);
                }

                return $header;
            },
            $headers,
        );
    }

    /**
     * @param list<string> $headers
     */
    private function requiredColumnIndex(array $headers, string $column): int
    {
        foreach ($headers as $index => $header) {
            if ($header === $column) {
                return $index;
            }
        }

        throw new RuntimeException(sprintf('Missing required Jira CSV column: %s', $column));
    }

    /**
     * @param list<string> $headers
     * @return list<int>
     */
    private function worklogIndexes(array $headers): array
    {
        $indexes = [];

        foreach ($headers as $index => $header) {
            if ($header === 'Rejestruj pracę') {
                $indexes[] = $index;
            }
        }

        if ($indexes === []) {
            throw new RuntimeException('Missing required Jira CSV column: Rejestruj pracę');
        }

        return $indexes;
    }

    /**
     * @return array{string, int}
     */
    private function parseWorklog(string $worklog, int $rowNumber): array
    {
        $segments = array_map('trim', explode(';', $worklog));

        if (count($segments) !== 4) {
            throw new RuntimeException(sprintf(
                'Worklog must have 4 semicolon-separated segments at row %d',
                $rowNumber,
            ));
        }

        [, $jiraDate, , $seconds] = $segments;

        if ($seconds === '' || !ctype_digit($seconds)) {
            throw new RuntimeException(sprintf('Worklog seconds must be an integer at row %d', $rowNumber));
        }

        return [$this->parseJiraDate($jiraDate, $rowNumber), (int) $seconds];
    }

    private function parseJiraDate(string $jiraDate, int $rowNumber): string
    {
        if (!preg_match('/^(\d{1,2})\/(\p{L}+)\/(\d{2})\s+(\d{1,2}):(\d{2})\s+(AM|PM)$/u', $jiraDate, $matches)) {
            throw new RuntimeException(sprintf('Unsupported Jira worklog date at row %d: %s', $rowNumber, $jiraDate));
        }

        $month = $this->monthNumber(mb_strtolower($matches[2], 'UTF-8'), $rowNumber);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d g:i A',
            sprintf(
                '20%02d-%02d-%02d %d:%02d %s',
                (int) $matches[3],
                $month,
                (int) $matches[1],
                (int) $matches[4],
                (int) $matches[5],
                $matches[6],
            ),
            new DateTimeZone('UTC'),
        );

        if ($date === false) {
            throw new RuntimeException(sprintf('Unsupported Jira worklog date at row %d: %s', $rowNumber, $jiraDate));
        }

        return $date->format('Y-m-d');
    }

    private function monthNumber(string $month, int $rowNumber): int
    {
        $months = [
            'sty' => 1,
            'lut' => 2,
            'mar' => 3,
            'kwi' => 4,
            'maj' => 5,
            'cze' => 6,
            'lip' => 7,
            'sie' => 8,
            'wrz' => 9,
            'paź' => 10,
            'paz' => 10,
            'lis' => 11,
            'gru' => 12,
        ];

        if (!isset($months[$month])) {
            throw new RuntimeException(sprintf('Unsupported Jira month at row %d: %s', $rowNumber, $month));
        }

        return $months[$month];
    }
}
