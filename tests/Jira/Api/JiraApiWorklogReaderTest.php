<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira\Api;

use JiraTimesheet\Jira\Api\JiraApiException;
use JiraTimesheet\Jira\Api\JiraApiWorklogReader;
use JiraTimesheet\Jira\Api\JiraHttpClient;
use JiraTimesheet\Jira\Api\JiraIssue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JiraApiWorklogReaderTest extends TestCase
{
    #[Test]
    public function it_reads_current_user_worklogs_by_started_date_in_report_timezone(): void
    {
        $client = new WorklogRecordingJiraHttpClient([
            ['accountId' => 'me-123'],
            [
                'startAt' => 0,
                'maxResults' => 2,
                'total' => 3,
                'worklogs' => [
                    [
                        'id' => '10001',
                        'created' => '2026-05-21T12:00:00.000+0000',
                        'started' => '2026-05-01T23:30:00.000+0000',
                        'timeSpentSeconds' => 3600,
                        'author' => ['accountId' => 'me-123'],
                    ],
                    [
                        'id' => '10002',
                        'started' => '2026-05-02T10:00:00.000+0000',
                        'timeSpentSeconds' => 7200,
                        'author' => ['accountId' => 'other-user'],
                    ],
                ],
            ],
            [
                'startAt' => 2,
                'maxResults' => 2,
                'total' => 3,
                'worklogs' => [
                    [
                        'id' => '10003',
                        'started' => '2026-05-03T00:00:00.000+0200',
                        'timeSpentSeconds' => 1800,
                        'author' => ['accountId' => 'me-123'],
                    ],
                ],
            ],
        ]);

        $entries = (new JiraApiWorklogReader($client, 2))->read(
            [new JiraIssue('PAR-1', 'First issue')],
            '2026-05-02',
            '2026-05-03',
            'Europe/Warsaw',
        );

        self::assertCount(1, $entries);
        self::assertSame('2026-05-02', $entries[0]->date);
        self::assertSame('PAR-1', $entries[0]->issueKey);
        self::assertSame('First issue', $entries[0]->summary);
        self::assertSame(3600, $entries[0]->seconds);

        self::assertSame('GET', $client->requests[0]['method']);
        self::assertSame('/rest/api/3/myself', $client->requests[0]['path']);
        self::assertSame('/rest/api/3/issue/PAR-1/worklog?startAt=0&maxResults=2', $client->requests[1]['path']);
        self::assertSame('/rest/api/3/issue/PAR-1/worklog?startAt=2&maxResults=2', $client->requests[2]['path']);
    }

    #[Test]
    public function it_rejects_current_user_responses_without_account_id(): void
    {
        $client = new WorklogRecordingJiraHttpClient([[]]);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Jira current user response does not contain accountId');

        (new JiraApiWorklogReader($client))->read(
            [new JiraIssue('PAR-1', 'First issue')],
            '2026-05-02',
            '2026-05-03',
            'Europe/Warsaw',
        );
    }
}

final class WorklogRecordingJiraHttpClient implements JiraHttpClient
{
    /**
     * @var list<array{method:string, path:string, body:null|array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @param list<array<string, mixed>> $responses
     */
    public function __construct(private array $responses)
    {
    }

    /**
     * @param null|array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ];

        return \array_shift($this->responses) ?? [];
    }
}
