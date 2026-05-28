<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Jira\WorklogEntry;

final readonly class JiraWorklogProvider
{
    public function __construct(
        private JiraHttpClient $httpClient,
    ) {
    }

    /**
     * @return list<WorklogEntry>
     */
    public function read(JiraApiConfig $config): array
    {
        $issues = (new JiraIssueSource($this->httpClient))->search($config->jql);

        return (new JiraApiWorklogReader($this->httpClient))->read(
            $issues,
            $config->from,
            $config->to,
            $config->timezone,
        );
    }
}
