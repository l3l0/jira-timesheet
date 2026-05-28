<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JiraTimesheet\Jira\WorklogEntry;

final readonly class JiraApiWorklogReader
{
    public function __construct(
        private JiraHttpClient $client,
        private int $maxResults = 100,
    ) {
        if ($this->maxResults < 1) {
            throw new JiraApiException('Jira worklog reader maxResults must be greater than zero');
        }
    }

    /**
     * @param list<JiraIssue> $issues
     * @return list<WorklogEntry>
     */
    public function read(array $issues, string $from, string $to, string $timezone): array
    {
        $reportTimezone = $this->timezone($timezone);
        $fromDate = $this->dayBoundary($from, $reportTimezone, 'from');
        $toDate = $this->dayBoundary($to, $reportTimezone, 'to');
        $currentUser = $this->currentUser();
        $entries = [];

        foreach ($issues as $issue) {
            foreach ($this->worklogs($issue) as $worklog) {
                $entry = $this->mapWorklog($issue, $worklog, $currentUser->accountId, $reportTimezone, $fromDate, $toDate);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    private function currentUser(): JiraUser
    {
        $response = $this->client->request('GET', '/rest/api/3/myself');
        $accountId = $response['accountId'] ?? null;

        if (!\is_string($accountId) || $accountId === '') {
            throw new JiraApiException('Jira current user response does not contain accountId');
        }

        return new JiraUser($accountId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function worklogs(JiraIssue $issue): array
    {
        $worklogs = [];
        $startAt = 0;

        do {
            $response = $this->client->request(
                'GET',
                \sprintf(
                    '/rest/api/3/issue/%s/worklog?startAt=%d&maxResults=%d',
                    \rawurlencode($issue->key),
                    $startAt,
                    $this->maxResults,
                ),
            );
            $pageWorklogs = $this->worklogRows($response);

            foreach ($pageWorklogs as $worklog) {
                $worklogs[] = $worklog;
            }

            $startAt += \count($pageWorklogs);
            $total = \is_int($response['total'] ?? null) ? $response['total'] : $startAt;
        } while ($pageWorklogs !== [] && $startAt < $total);

        return $worklogs;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function worklogRows(array $response): array
    {
        $worklogs = $response['worklogs'] ?? [];

        if (!\is_array($worklogs)) {
            throw new JiraApiException('Jira worklog response field worklogs must be an array');
        }

        /** @var list<array<string, mixed>> $worklogs */
        return $worklogs;
    }

    /**
     * @param array<string, mixed> $worklog
     */
    private function mapWorklog(
        JiraIssue $issue,
        array $worklog,
        string $currentUserAccountId,
        DateTimeZone $reportTimezone,
        DateTimeImmutable $fromDate,
        DateTimeImmutable $toDate,
    ): ?WorklogEntry {
        $author = $worklog['author'] ?? [];
        $authorAccountId = \is_array($author) ? ($author['accountId'] ?? null) : null;

        if ($authorAccountId !== $currentUserAccountId) {
            return null;
        }

        $started = $worklog['started'] ?? null;
        $seconds = $worklog['timeSpentSeconds'] ?? null;

        if (!\is_string($started) || !\is_int($seconds)) {
            throw new JiraApiException('Jira worklog response contains a malformed worklog row');
        }

        $startedAt = $this->dateTime($started)->setTimezone($reportTimezone);

        if ($startedAt < $fromDate || $startedAt >= $toDate) {
            return null;
        }

        return new WorklogEntry($startedAt->format('Y-m-d'), $issue->key, $issue->summary, $seconds);
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Exception $exception) {
            throw new JiraApiException(\sprintf('Invalid Jira report timezone: %s', $timezone), previous: $exception);
        }
    }

    private function dayBoundary(string $date, DateTimeZone $timezone, string $name): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new JiraApiException(\sprintf('Jira API option %s must use YYYY-MM-DD format', $name));
        }

        return $parsed;
    }

    private function dateTime(string $dateTime): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($dateTime);
        } catch (Exception $exception) {
            throw new JiraApiException('Jira worklog response contains invalid started date', previous: $exception);
        }
    }
}
