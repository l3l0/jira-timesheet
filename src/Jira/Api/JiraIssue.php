<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

final readonly class JiraIssue
{
    public function __construct(
        public string $key,
        public string $summary,
    ) {
    }
}
