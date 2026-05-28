<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Config;

use function file_put_contents;

use JiraTimesheet\Config\ConfigurationException;
use JiraTimesheet\Config\JiraApiConfigResolver;
use JiraTimesheet\Config\SymfonyDotEnvLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function tempnam;

final class JiraApiConfigResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_config_with_cli_env_dotenv_precedence(): void
    {
        $envPath = (string) tempnam(sys_get_temp_dir(), 'jira-env-');
        file_put_contents($envPath, <<<'ENV'
JIRA_BASE_URL=https://dotenv.atlassian.net
JIRA_EMAIL=dotenv@example.com
JIRA_API_TOKEN=dotenv-token
JIRA_JQL="project = DOTENV"
JIRA_TIMEZONE=UTC
JIRA_FROM=2026-05-01
JIRA_TO=2026-06-01
JIRA_OUTPUT=/tmp/dotenv.csv
ENV);

        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), [
            'JIRA_EMAIL' => 'env@example.com',
            'JIRA_API_TOKEN' => 'env-token',
            'JIRA_JQL' => 'project = ENV',
        ]);

        $config = $resolver->resolve([
            '--env', $envPath,
            '--base-url', 'https://cli.atlassian.net/',
            '--jql', 'project = CLI',
            '--from', '2026-05-10',
            '--to', '2026-05-20',
            '--output', '/tmp/cli.csv',
        ]);

        self::assertSame('https://cli.atlassian.net', $config->baseUrl);
        self::assertSame('env@example.com', $config->email);
        self::assertSame('env-token', $config->apiToken);
        self::assertSame('project = CLI', $config->jql);
        self::assertSame('2026-05-10', $config->from);
        self::assertSame('2026-05-20', $config->to);
        self::assertSame('/tmp/cli.csv', $config->output);
        self::assertSame('UTC', $config->timezone);
        self::assertSame('********', $config->maskedApiToken());
    }

    #[Test]
    public function it_ignores_empty_environment_values_when_dotenv_provides_api_config(): void
    {
        $envPath = (string) tempnam(sys_get_temp_dir(), 'jira-env-');
        file_put_contents($envPath, <<<'ENV'
JIRA_BASE_URL=https://dotenv.atlassian.net
JIRA_EMAIL=dotenv@example.com
JIRA_API_TOKEN=dotenv-token
JIRA_JQL="project = DOTENV"
JIRA_TIMEZONE=UTC
JIRA_FROM=2026-05-01
JIRA_TO=2026-06-01
JIRA_OUTPUT=/tmp/dotenv.csv
ENV);

        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), [
            'JIRA_BASE_URL' => '',
            'JIRA_EMAIL' => '',
            'JIRA_API_TOKEN' => '',
            'JIRA_JQL' => '',
            'JIRA_TIMEZONE' => '',
            'JIRA_FROM' => '',
            'JIRA_TO' => '',
            'JIRA_OUTPUT' => '',
        ]);

        $config = $resolver->resolve(['--env', $envPath]);

        self::assertSame('https://dotenv.atlassian.net', $config->baseUrl);
        self::assertSame('dotenv@example.com', $config->email);
        self::assertSame('dotenv-token', $config->apiToken);
        self::assertSame('project = DOTENV', $config->jql);
        self::assertSame('2026-05-01', $config->from);
        self::assertSame('2026-06-01', $config->to);
        self::assertSame('/tmp/dotenv.csv', $config->output);
        self::assertSame('UTC', $config->timezone);
    }

    #[Test]
    public function it_requires_jira_credentials_and_report_parameters(): void
    {
        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Missing required Jira API configuration: JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN, JIRA_JQL, from, to, output');

        $resolver->resolve([]);
    }

    #[Test]
    public function it_does_not_leak_token_values_in_validation_errors(): void
    {
        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), [
            'JIRA_BASE_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'super-secret-token',
            'JIRA_JQL' => 'project = PAR',
        ]);

        try {
            $resolver->resolve(['--from', '2026-06-01', '--to', '2026-05-01', '--output', '/tmp/out.csv']);
            self::fail('Expected configuration exception.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('from must be earlier than to', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-token', $exception->getMessage());
        }
    }

    #[Test]
    public function it_rejects_missing_explicit_env_files(): void
    {
        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configured .env file is not readable');

        $resolver->resolve(['--env', '/no/such/.env']);
    }

    #[Test]
    public function it_rejects_unknown_options(): void
    {
        $resolver = new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown Jira API option: --unknown');

        $resolver->resolve(['--unknown', 'value']);
    }
}
