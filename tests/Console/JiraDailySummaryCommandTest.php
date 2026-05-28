<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Console;

use Closure;

use function file_put_contents;

use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Config\JiraApiConfigResolver;
use JiraTimesheet\Config\SymfonyDotEnvLoader;
use JiraTimesheet\Console\JiraDailySummaryCommand;
use JiraTimesheet\Console\JiraTimesheetCommand;
use JiraTimesheet\Jira\WorklogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

use function sys_get_temp_dir;
use function tempnam;

final class JiraDailySummaryCommandTest extends TestCase
{
    #[Test]
    public function it_prints_daily_summary_from_jira_api_without_requiring_output_config(): void
    {
        $envPath = (string) tempnam(sys_get_temp_dir(), 'jira-env-');
        file_put_contents($envPath, <<<'ENV'
JIRA_BASE_URL=https://example.atlassian.net
JIRA_EMAIL=user@example.com
JIRA_API_TOKEN=super-secret-token
JIRA_JQL="project = PAR"
JIRA_FROM=2026-05-01
JIRA_TO=2026-06-01
ENV);

        [$stdout, $stderr] = $this->runApplication(
            ['command' => 'api:daily-summary', '--env' => $envPath],
            static fn (): array => [
                new WorklogEntry('2026-05-02', 'PAR-1', 'First issue', 3600),
                new WorklogEntry('2026-05-02', 'PAR-2', 'Second issue', 9000),
                new WorklogEntry('2026-05-03', 'PAR-3', 'Third issue', 7200),
            ],
        );

        self::assertSame(0, $stdout['exitCode']);
        self::assertSame('', $stderr['output']);
        self::assertStringContainsString('Date', $stdout['output']);
        self::assertStringContainsString('Hours', $stdout['output']);
        self::assertStringContainsString('Seconds', $stdout['output']);
        self::assertStringContainsString('2026-05-02', $stdout['output']);
        self::assertStringContainsString('3.50', $stdout['output']);
        self::assertStringContainsString('12600', $stdout['output']);
        self::assertStringContainsString('2026-05-03', $stdout['output']);
        self::assertStringContainsString('2.00', $stdout['output']);
        self::assertStringContainsString('7200', $stdout['output']);
        self::assertStringContainsString('TOTAL', $stdout['output']);
        self::assertStringContainsString('5.50', $stdout['output']);
        self::assertStringContainsString('19800', $stdout['output']);
        self::assertStringNotContainsString('super-secret-token', $stdout['output'] . $stderr['output']);
        self::assertStringNotContainsString(\base64_encode('user@example.com:super-secret-token'), $stdout['output'] . $stderr['output']);
    }

    #[Test]
    public function it_reports_missing_configuration_without_leaking_token(): void
    {
        [$stdout, $stderr] = $this->runApplication(
            ['command' => 'api:daily-summary', '--from' => '2026-05-01', '--to' => '2026-06-01'],
            static fn (): array => [],
            new JiraApiConfigResolver(new SymfonyDotEnvLoader(), ['JIRA_API_TOKEN' => 'super-secret-token']),
        );

        self::assertSame(1, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('Missing required Jira API configuration', $stderr['output']);
        self::assertStringNotContainsString('super-secret-token', $stdout['output'] . $stderr['output']);
        self::assertStringNotContainsString(\base64_encode('user@example.com:super-secret-token'), $stdout['output'] . $stderr['output']);
    }

    #[Test]
    public function it_keeps_legacy_csv_command_usable_when_daily_summary_command_is_registered(): void
    {
        $outputPath = (string) tempnam(sys_get_temp_dir(), 'jira-output-');

        [$stdout, $stderr] = $this->runApplication([
            'mode' => __DIR__ . '/../../raports/jira_raport_20260521.csv',
            'output' => $outputPath,
        ]);

        self::assertSame(0, $stdout['exitCode']);
        self::assertSame('', $stderr['output']);
        self::assertStringContainsString('TST-1', $stdout['output']);
        self::assertStringContainsString('TOTAL', $stdout['output']);
        self::assertFileExists($outputPath);
    }

    /**
     * @param array<string, string> $input
     * @param null|Closure(JiraApiConfig): list<WorklogEntry> $apiEntriesProvider
     * @return array{array{exitCode:int, output:string}, array{output:string}}
     */
    private function runApplication(
        array $input,
        ?Closure $apiEntriesProvider = null,
        ?JiraApiConfigResolver $configResolver = null,
    ): array {
        $configResolver ??= new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []);
        $application = new Application('jira-timesheet');
        $legacyCommand = new JiraTimesheetCommand(configResolver: $configResolver, apiEntriesProvider: $apiEntriesProvider);

        $application->addCommand($legacyCommand);
        $application->addCommand(new JiraDailySummaryCommand(
            configResolver: $configResolver,
            apiEntriesProvider: $apiEntriesProvider,
        ));
        if (!isset($input['command'])) {
            $application->setDefaultCommand((string) $legacyCommand->getName(), true);
        }

        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $exitCode = $tester->run($input, ['capture_stderr_separately' => true]);

        return [
            [
                'exitCode' => $exitCode,
                'output' => $tester->getDisplay(),
            ],
            [
                'output' => $tester->getErrorOutput(),
            ],
        ];
    }
}
