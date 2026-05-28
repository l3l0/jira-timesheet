<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira\Api;

use JiraTimesheet\Jira\Api\JiraApiException;
use JiraTimesheet\Jira\Api\JiraHttpClient;
use JiraTimesheet\Jira\Api\JiraIssueSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JiraIssueSourceTest extends TestCase
{
    #[Test]
    public function it_fetches_issues_from_jql_with_next_page_token_pagination(): void
    {
        $client = new RecordingJiraHttpClient([
            [
                'isLast' => false,
                'nextPageToken' => 'page-2',
                'issues' => [
                    ['key' => 'PAR-1', 'fields' => ['summary' => 'First issue']],
                ],
            ],
            [
                'isLast' => true,
                'issues' => [
                    ['key' => 'PAR-2', 'fields' => ['summary' => 'Second issue']],
                ],
            ],
        ]);

        $issues = (new JiraIssueSource($client, 50))->search('project = PAR');

        self::assertCount(2, $issues);
        self::assertSame('PAR-1', $issues[0]->key);
        self::assertSame('First issue', $issues[0]->summary);
        self::assertSame('PAR-2', $issues[1]->key);
        self::assertSame('Second issue', $issues[1]->summary);

        self::assertSame('POST', $client->requests[0]['method']);
        self::assertSame('/rest/api/3/search/jql', $client->requests[0]['path']);
        self::assertSame([
            'jql' => 'project = PAR',
            'fields' => ['summary'],
            'maxResults' => 50,
        ], $client->requests[0]['body']);
        self::assertArrayNotHasKey('nextPageToken', $client->requests[0]['body']);

        self::assertSame([
            'jql' => 'project = PAR',
            'fields' => ['summary'],
            'maxResults' => 50,
            'nextPageToken' => 'page-2',
        ], $client->requests[1]['body']);
    }

    #[Test]
    public function it_rejects_search_responses_without_next_page_token_when_not_last(): void
    {
        $client = new RecordingJiraHttpClient([
            [
                'isLast' => false,
                'issues' => [],
            ],
        ]);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Jira search response is not last but does not contain nextPageToken');

        (new JiraIssueSource($client))->search('project = PAR');
    }

    #[Test]
    public function it_rejects_malformed_issue_rows(): void
    {
        $client = new RecordingJiraHttpClient([
            [
                'isLast' => true,
                'issues' => [
                    ['fields' => ['summary' => 'Missing key']],
                ],
            ],
        ]);

        $this->expectException(JiraApiException::class);
        $this->expectExceptionMessage('Jira search response contains an issue without key');

        (new JiraIssueSource($client))->search('project = PAR');
    }
}

final class RecordingJiraHttpClient implements JiraHttpClient
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
