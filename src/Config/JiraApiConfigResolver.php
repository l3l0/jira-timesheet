<?php

declare(strict_types=1);

namespace JiraTimesheet\Config;

use DateTimeImmutable;

final class JiraApiConfigResolver
{
    /**
     * @param null|array<string, string|false> $environment
     */
    public function __construct(
        private readonly SymfonyDotEnvLoader $dotEnvLoader = new SymfonyDotEnvLoader(),
        private readonly ?array $environment = null,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function resolve(array $arguments, string $workingDirectory = '.', bool $requireOutput = true): JiraApiConfig
    {
        $options = $this->parseOptions($arguments);
        $explicitEnvPath = \array_key_exists('env', $options);
        $envPath = (string) ($options['env'] ?? $workingDirectory . '/.env');
        $dotenv = [];

        if ($explicitEnvPath || \is_file($envPath)) {
            $dotenv = $this->dotEnvLoader->load($envPath);
        }

        $environment = $this->normalizedEnvironment();
        $values = \array_merge($dotenv, $environment);

        $baseUrl = $this->stringValue($options['base-url'] ?? $values['JIRA_BASE_URL'] ?? '');
        $email = $this->stringValue($options['email'] ?? $values['JIRA_EMAIL'] ?? '');
        $apiToken = $this->stringValue($options['token'] ?? $options['api-token'] ?? $values['JIRA_API_TOKEN'] ?? '');
        $jql = $this->stringValue($options['jql'] ?? $values['JIRA_JQL'] ?? '');
        $from = $this->stringValue($options['from'] ?? $values['JIRA_FROM'] ?? '');
        $to = $this->stringValue($options['to'] ?? $values['JIRA_TO'] ?? '');
        $output = $this->stringValue($options['output'] ?? $values['JIRA_OUTPUT'] ?? '');
        $timezone = $this->stringValue($options['timezone'] ?? $values['JIRA_TIMEZONE'] ?? 'Europe/Warsaw');

        $required = [
            'JIRA_BASE_URL' => $baseUrl,
            'JIRA_EMAIL' => $email,
            'JIRA_API_TOKEN' => $apiToken,
            'JIRA_JQL' => $jql,
            'from' => $from,
            'to' => $to,
        ];

        if ($requireOutput) {
            $required['output'] = $output;
        }

        $this->assertRequired($required);
        $this->assertDateRange($from, $to);

        return new JiraApiConfig(
            $this->normalizeBaseUrl($baseUrl),
            $email,
            $apiToken,
            $jql,
            $from,
            $to,
            $timezone,
            $output,
        );
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private function parseOptions(array $arguments): array
    {
        $options = [];
        $allowed = [
            'api-token',
            'base-url',
            'email',
            'env',
            'from',
            'jql',
            'output',
            'timezone',
            'to',
            'token',
        ];

        for ($index = 0; $index < \count($arguments); ++$index) {
            $argument = $arguments[$index];

            if (!\str_starts_with($argument, '--')) {
                throw new ConfigurationException(\sprintf('Unexpected Jira API argument: %s', $argument));
            }

            $name = \substr($argument, 2);
            $value = null;

            if (\str_contains($name, '=')) {
                [$name, $value] = \explode('=', $name, 2);
            }

            if (!\in_array($name, $allowed, true)) {
                throw new ConfigurationException(\sprintf('Unknown Jira API option: --%s', $name));
            }

            if ($value === null) {
                $next = $arguments[$index + 1] ?? null;

                if ($next === null || \str_starts_with($next, '--')) {
                    throw new ConfigurationException(\sprintf('Missing value for Jira API option: --%s', $name));
                }

                $value = $next;
                ++$index;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedEnvironment(): array
    {
        $environment = $this->environment ?? \getenv();
        $normalized = [];

        foreach ($environment as $key => $value) {
            if (\is_string($value) && \trim($value) !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        return \trim((string) $value);
    }

    /**
     * @param array<string, string> $values
     */
    private function assertRequired(array $values): void
    {
        $missing = [];

        foreach ($values as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new ConfigurationException(
                'Missing required Jira API configuration: ' . \implode(', ', $missing),
            );
        }
    }

    private function assertDateRange(string $from, string $to): void
    {
        $fromDate = $this->date($from, 'from');
        $toDate = $this->date($to, 'to');

        if ($fromDate >= $toDate) {
            throw new ConfigurationException('Jira API date range is invalid: from must be earlier than to');
        }
    }

    private function date(string $date, string $name): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ConfigurationException(\sprintf('Jira API option %s must use YYYY-MM-DD format', $name));
        }

        return $parsed;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        return \rtrim($baseUrl, '/');
    }
}
