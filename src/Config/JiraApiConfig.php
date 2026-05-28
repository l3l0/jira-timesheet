<?php

declare(strict_types=1);

namespace JiraTimesheet\Config;

final readonly class JiraApiConfig
{
    public function __construct(
        public string $baseUrl,
        public string $email,
        public string $apiToken,
        public string $jql,
        public string $from,
        public string $to,
        public string $timezone,
        public string $output,
    ) {
    }

    public function maskedApiToken(): string
    {
        return '********';
    }
}
