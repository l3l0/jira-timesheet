<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

interface JiraHttpClient
{
    /**
     * @param null|array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array;
}
