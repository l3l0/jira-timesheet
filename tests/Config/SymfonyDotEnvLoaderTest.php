<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Config;

use function file_put_contents;

use JiraTimesheet\Config\ConfigurationException;
use JiraTimesheet\Config\SymfonyDotEnvLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function tempnam;

final class SymfonyDotEnvLoaderTest extends TestCase
{
    #[Test]
    public function it_reads_simple_dotenv_files_with_symfony_dotenv_without_exporting_to_process_env(): void
    {
        $path = (string) tempnam(\sys_get_temp_dir(), 'jira-env-');
        file_put_contents($path, <<<'ENV'
# Jira credentials
JIRA_BASE_URL=https://example.atlassian.net
export JIRA_EMAIL="user@example.com"
JIRA_API_TOKEN='secret-token'
JIRA_JQL='project = PAR AND worklogDate >= "2026-05-01"'
JIRA_OUTPUT_DIR=/tmp
JIRA_OUTPUT=${JIRA_OUTPUT_DIR}/jira.csv

ENV);

        $_ENV['JIRA_API_TOKEN'] = 'original-env-token';
        $_SERVER['JIRA_API_TOKEN'] = 'original-server-token';

        $values = (new SymfonyDotEnvLoader())->load($path);

        self::assertSame('https://example.atlassian.net', $values['JIRA_BASE_URL']);
        self::assertSame('user@example.com', $values['JIRA_EMAIL']);
        self::assertSame('secret-token', $values['JIRA_API_TOKEN']);
        self::assertSame('project = PAR AND worklogDate >= "2026-05-01"', $values['JIRA_JQL']);
        self::assertSame('/tmp/jira.csv', $values['JIRA_OUTPUT']);
        self::assertSame('original-env-token', $_ENV['JIRA_API_TOKEN']);
        self::assertSame('original-server-token', $_SERVER['JIRA_API_TOKEN']);
        self::assertNotSame('secret-token', \getenv('JIRA_API_TOKEN'));
    }

    #[Test]
    public function it_rejects_malformed_dotenv_lines(): void
    {
        $path = (string) tempnam(\sys_get_temp_dir(), 'jira-env-');
        file_put_contents($path, "\n# comment\nnot a key\nJIRA_TIMEZONE=Europe/Warsaw\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configured .env file is invalid');

        (new SymfonyDotEnvLoader())->load($path);
    }
}
