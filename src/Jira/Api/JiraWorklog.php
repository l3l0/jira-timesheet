<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

final readonly class JiraWorklog
{
    public function __construct(
        public string $issueKey,
        public string $issueSummary,
        public string $id,
        public string $started,
        public int $timeSpentSeconds,
        public string $authorAccountId,
    ) {
    }
}
