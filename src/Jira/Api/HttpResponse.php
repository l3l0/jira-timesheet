<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

final readonly class HttpResponse
{
    /**
     * @param list<string> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }
}
