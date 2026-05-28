<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira;

use JiraTimesheet\Jira\JiraCsvReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JiraCsvReaderTest extends TestCase
{
    #[Test]
    public function it_reads_bom_csv_and_duplicate_worklog_columns(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie', 'Rejestruj pracę', 'Rejestruj pracę'],
            [
                [
                    'PAR-2',
                    'Task one',
                    'first log;21/maj/26 1:48 PM;Primo;1800',
                    'second log;21/maj/26 2:18 PM;Primo;5400',
                ],
            ],
            bom: true,
        );

        $entries = (new JiraCsvReader())->read($path);

        self::assertCount(2, $entries);
        self::assertSame('2026-05-21', $entries[0]->date);
        self::assertSame('PAR-2', $entries[0]->issueKey);
        self::assertSame('Task one', $entries[0]->summary);
        self::assertSame(1800, $entries[0]->seconds);
        self::assertSame(5400, $entries[1]->seconds);
    }

    #[Test]
    public function it_iterates_duplicate_worklog_columns_without_returning_an_array(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie', 'Rejestruj pracę', 'Rejestruj pracę'],
            [
                [
                    'PAR-2',
                    'Task one',
                    'first log;21/maj/26 1:48 PM;Primo;1800',
                    'second log;21/maj/26 2:18 PM;Primo;5400',
                ],
            ],
            bom: true,
        );

        $entries = (new JiraCsvReader())->readIterable($path);

        self::assertInstanceOf(\Traversable::class, $entries);

        $entries = \iterator_to_array($entries, preserve_keys: false);

        self::assertCount(2, $entries);
        self::assertSame('PAR-2', $entries[0]->issueKey);
        self::assertSame('Task one', $entries[0]->summary);
        self::assertSame(1800, $entries[0]->seconds);
        self::assertSame(5400, $entries[1]->seconds);
    }

    #[Test]
    public function it_reads_a_polish_month_other_than_may(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie', 'Rejestruj pracę'],
            [['PAR-3', 'June task', 'june log;02/cze/26 9:00 AM;Primo;3600']],
        );

        $entries = (new JiraCsvReader())->read($path);

        self::assertCount(1, $entries);
        self::assertSame('2026-06-02', $entries[0]->date);
    }

    #[Test]
    public function it_skips_empty_worklog_cells(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie', 'Rejestruj pracę', 'Rejestruj pracę'],
            [['PAR-4', 'Partial task', '', 'valid log;21/maj/26 3:00 PM;Primo;900']],
        );

        $entries = (new JiraCsvReader())->read($path);

        self::assertCount(1, $entries);
        self::assertSame(900, $entries[0]->seconds);
    }

    #[Test]
    public function it_fails_for_worklog_with_unexpected_segment_count(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie', 'Rejestruj pracę'],
            [['PAR-5', 'Broken task', 'too;few;segments']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worklog must have 4 semicolon-separated segments');

        (new JiraCsvReader())->read($path);
    }

    #[Test]
    public function it_fails_when_required_columns_are_missing(): void
    {
        $path = $this->writeCsv(
            ['Klucz zgłoszenia', 'Podsumowanie'],
            [['PAR-6', 'No worklogs']],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required Jira CSV column: Rejestruj pracę');

        (new JiraCsvReader())->read($path);
    }

    #[Test]
    public function it_reads_the_reference_jira_export(): void
    {
        $entries = (new JiraCsvReader())->read(__DIR__ . '/../../raports/jira_raport_20260521.csv');

        self::assertCount(6, $entries);
        self::assertSame(57600, \array_sum(\array_map(
            static fn ($entry): int => $entry->seconds,
            $entries,
        )));
        self::assertSame(['2026-05-21'], \array_values(\array_unique(\array_map(
            static fn ($entry): string => $entry->date,
            $entries,
        ))));
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function writeCsv(array $headers, array $rows, bool $bom = false): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'jira-csv-');
        $stream = \fopen($path, 'wb');
        self::assertIsResource($stream);

        if ($bom) {
            \fwrite($stream, "\xEF\xBB\xBF");
        }

        \fputcsv($stream, $headers, escape: '');

        foreach ($rows as $row) {
            \fputcsv($stream, $row, escape: '');
        }

        \fclose($stream);

        return $path;
    }
}
