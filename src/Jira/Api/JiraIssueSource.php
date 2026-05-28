<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

final readonly class JiraIssueSource
{
    public function __construct(
        private JiraHttpClient $client,
        private int $maxResults = 100,
    ) {
        if ($this->maxResults < 1) {
            throw new JiraApiException('Jira issue source maxResults must be greater than zero');
        }
    }

    /**
     * @return list<JiraIssue>
     */
    public function search(string $jql): array
    {
        $issues = [];
        $nextPageToken = null;

        do {
            $body = [
                'jql' => $jql,
                'fields' => ['summary'],
                'maxResults' => $this->maxResults,
            ];

            if ($nextPageToken !== null) {
                $body['nextPageToken'] = $nextPageToken;
            }

            $response = $this->client->request('POST', '/rest/api/3/search/jql', $body);

            foreach ($this->issueRows($response) as $issue) {
                $issues[] = $this->mapIssue($issue);
            }

            $isLast = $response['isLast'] ?? true;

            if ($isLast === true) {
                break;
            }

            $nextPageToken = $response['nextPageToken'] ?? null;

            if (!\is_string($nextPageToken) || $nextPageToken === '') {
                throw new JiraApiException('Jira search response is not last but does not contain nextPageToken');
            }
        } while (true);

        return $issues;
    }

    /**
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function issueRows(array $response): array
    {
        $issues = $response['issues'] ?? [];

        if (!\is_array($issues)) {
            throw new JiraApiException('Jira search response field issues must be an array');
        }

        /** @var list<array<string, mixed>> $issues */
        return $issues;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function mapIssue(array $issue): JiraIssue
    {
        $key = $issue['key'] ?? null;

        if (!\is_string($key) || $key === '') {
            throw new JiraApiException('Jira search response contains an issue without key');
        }

        $fields = $issue['fields'] ?? [];
        $summary = \is_array($fields) && \is_string($fields['summary'] ?? null)
            ? $fields['summary']
            : '';

        return new JiraIssue($key, $summary);
    }
}
