<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira;

final readonly class WorklogEntry
{
    public function __construct(
        public string $date,
        public string $issueKey,
        public string $summary,
        public int $seconds,
    ) {
    }
}
