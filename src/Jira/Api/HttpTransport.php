<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

interface HttpTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
