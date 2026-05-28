<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

final readonly class JiraUser
{
    public function __construct(
        public string $accountId,
    ) {
    }
}
