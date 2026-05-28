<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Console;

use function array_map;
use function array_slice;

use Closure;

use function fclose;
use function fgetcsv;
use function file_put_contents;

use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Config\JiraApiConfigResolver;
use JiraTimesheet\Config\SymfonyDotEnvLoader;
use JiraTimesheet\Console\JiraTimesheetCommand;
use JiraTimesheet\Jira\Api\JiraBasicAuthHttpClient;
use JiraTimesheet\Jira\WorklogEntry;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

use function sys_get_temp_dir;
use function tempnam;

final class JiraTimesheetCommandTest extends TestCase
{
    #[Test]
    public function it_prints_usage_for_help(): void
    {
        [$stdout, $stderr] = $this->runCommand(['jira-timesheet', '--help']);

        self::assertSame(0, $stdout['exitCode']);
        self::assertStringContainsString('jira-timesheet input.csv output.csv', $stdout['output']);
        self::assertStringContainsString('jira-timesheet api --from YYYY-MM-DD --to YYYY-MM-DD --output output.csv', $stdout['output']);
        self::assertSame('', $stderr['output']);
    }

    #[Test]
    public function it_requires_exactly_two_positional_arguments(): void
    {
        [$stdout, $stderr] = $this->runCommand(['jira-timesheet', 'input.csv']);

        self::assertSame(2, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('jira-timesheet input.csv output.csv', $stderr['output']);
        self::assertStringContainsString('jira-timesheet api --from YYYY-MM-DD --to YYYY-MM-DD --output output.csv', $stderr['output']);
    }

    #[Test]
    public function it_reports_missing_jira_api_configuration_for_api_mode(): void
    {
        [$stdout, $stderr] = $this->runCommand(
            ['jira-timesheet', 'api', '--from', '2026-05-01', '--to', '2026-06-01', '--output', '/tmp/out.csv'],
            new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []),
        );

        self::assertSame(1, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('Missing required Jira API configuration', $stderr['output']);
        self::assertStringNotContainsString('super-secret-token', $stderr['output']);
    }

    #[Test]
    public function it_generates_api_report_from_configured_api_source_without_leaking_token(): void
    {
        $envPath = (string) tempnam(sys_get_temp_dir(), 'jira-env-');
        $outputPath = (string) tempnam(sys_get_temp_dir(), 'jira-api-output-');
        file_put_contents($envPath, <<<'ENV'
JIRA_BASE_URL=https://example.atlassian.net
JIRA_EMAIL=user@example.com
JIRA_API_TOKEN=super-secret-token
JIRA_JQL="project = PAR"
JIRA_FROM=2026-05-01
JIRA_TO=2026-06-01
ENV);

        [$stdout, $stderr] = $this->runCommand(
            ['jira-timesheet', 'api', '--env', $envPath, '--output', $outputPath],
            new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []),
            static fn (): array => [
                new WorklogEntry('2026-05-02', 'PAR-1', 'First issue', 3600),
                new WorklogEntry('2026-05-02', 'PAR-1', 'First issue', 1800),
                new WorklogEntry('2026-05-02', 'PAR-2', 'Second issue', 7200),
            ],
        );

        self::assertSame(0, $stdout['exitCode']);
        self::assertStringContainsString('PAR-1', $stdout['output']);
        self::assertStringContainsString('PAR-2', $stdout['output']);
        self::assertStringContainsString('TOTAL', $stdout['output']);
        self::assertStringNotContainsString('super-secret-token', $stderr['output']);
        self::assertSame('', $stderr['output']);

        $rows = $this->readCsv($outputPath);

        self::assertSame(['2026-05-02', 'task', 'PAR-1', 'First issue', '1.50', '5400'], $rows[1]);
        self::assertSame(['2026-05-02', 'task', 'PAR-2', 'Second issue', '2.00', '7200'], $rows[2]);
        self::assertSame(['2026-05-02', 'total', '', 'TOTAL', '3.50', '12600'], $rows[3]);
    }

    #[Test]
    public function it_runs_api_mode_through_injected_psr18_http_client_without_real_network(): void
    {
        $envPath = (string) tempnam(sys_get_temp_dir(), 'jira-env-');
        $outputPath = (string) tempnam(sys_get_temp_dir(), 'jira-api-output-');
        file_put_contents($envPath, <<<'ENV'
JIRA_BASE_URL=https://example.atlassian.net
JIRA_EMAIL=user@example.com
JIRA_API_TOKEN=super-secret-token
JIRA_JQL="project = PAR"
JIRA_FROM=2026-05-01
JIRA_TO=2026-06-01
ENV);

        $psr18Client = new SequentialPsr18Client([
            new Response(200, [], '{"isLast":true,"issues":[{"key":"PAR-1","fields":{"summary":"First issue"}}]}'),
            new Response(200, [], '{"accountId":"me-123"}'),
            new Response(200, [], '{"startAt":0,"maxResults":100,"total":1,"worklogs":[{"id":"10001","started":"2026-05-02T10:00:00.000+0200","timeSpentSeconds":3600,"author":{"accountId":"me-123"}}]}'),
        ]);

        [$stdout, $stderr] = $this->runCommand(
            ['jira-timesheet', 'api', '--env', $envPath, '--output', $outputPath],
            new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []),
            null,
            static fn (JiraApiConfig $config): JiraBasicAuthHttpClient => new JiraBasicAuthHttpClient($config, $psr18Client),
        );

        self::assertSame(0, $stdout['exitCode']);
        self::assertSame('', $stderr['output']);
        self::assertStringContainsString('PAR-1', $stdout['output']);
        self::assertStringNotContainsString('super-secret-token', $stdout['output'] . $stderr['output']);
        self::assertCount(3, $psr18Client->requests);
        self::assertSame('POST', $psr18Client->requests[0]->getMethod());
        self::assertSame('https://example.atlassian.net/rest/api/3/search/jql', (string) $psr18Client->requests[0]->getUri());
        self::assertSame('Basic ' . \base64_encode('user@example.com:super-secret-token'), $psr18Client->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('{"jql":"project = PAR","fields":["summary"],"maxResults":100}', (string) $psr18Client->requests[0]->getBody());
        self::assertSame('https://example.atlassian.net/rest/api/3/myself', (string) $psr18Client->requests[1]->getUri());
        self::assertSame('https://example.atlassian.net/rest/api/3/issue/PAR-1/worklog?startAt=0&maxResults=100', (string) $psr18Client->requests[2]->getUri());

        $rows = $this->readCsv($outputPath);

        self::assertSame(['2026-05-02', 'task', 'PAR-1', 'First issue', '1.00', '3600'], $rows[1]);
        self::assertSame(['2026-05-02', 'total', '', 'TOTAL', '1.00', '3600'], $rows[2]);
    }

    #[Test]
    public function it_generates_csv_report_and_prints_table_for_valid_input(): void
    {
        $outputPath = (string) tempnam(sys_get_temp_dir(), 'jira-output-');

        [$stdout, $stderr] = $this->runCommand([
            'jira-timesheet',
            __DIR__ . '/../../raports/jira_raport_20260521.csv',
            $outputPath,
        ]);

        self::assertSame(0, $stdout['exitCode']);
        self::assertSame('', $stderr['output']);
        self::assertStringContainsString('TST-1', $stdout['output']);
        self::assertStringContainsString('TST-2', $stdout['output']);
        self::assertStringContainsString('TOTAL', $stdout['output']);
        self::assertStringContainsString('16.00', $stdout['output']);

        $rows = $this->readCsv($outputPath);

        self::assertSame(['date', 'row_type', 'issue_key', 'summary', 'hours', 'seconds'], $rows[0]);
        self::assertSame(['2026-05-21', 'task', 'TST-1'], array_slice($rows[1], 0, 3));
        self::assertSame(['14.00', '50400'], array_slice($rows[1], 4, 2));
        self::assertSame(['2026-05-21', 'task', 'TST-2'], array_slice($rows[2], 0, 3));
        self::assertSame(['2.00', '7200'], array_slice($rows[2], 4, 2));
        self::assertSame(['2026-05-21', 'total', '', 'TOTAL', '16.00', '57600'], $rows[3]);
    }

    #[Test]
    public function it_reports_missing_input_file_errors(): void
    {
        [$stdout, $stderr] = $this->runCommand(['jira-timesheet', '/no/such/file.csv', '/tmp/out.csv']);

        self::assertSame(1, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('Input CSV is not readable', $stderr['output']);
    }

    #[Test]
    public function it_reports_missing_required_columns(): void
    {
        $inputPath = (string) tempnam(sys_get_temp_dir(), 'jira-input-');
        file_put_contents($inputPath, "Klucz zgłoszenia,Podsumowanie\nPAR-1,Missing worklogs\n");

        [$stdout, $stderr] = $this->runCommand(['jira-timesheet', $inputPath, '/tmp/out.csv']);

        self::assertSame(1, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('Missing required Jira CSV column: Rejestruj pracę', $stderr['output']);
    }

    #[Test]
    public function it_reports_unwritable_output_without_raw_php_warning(): void
    {
        [$stdout, $stderr] = $this->runCommand([
            'jira-timesheet',
            __DIR__ . '/../../raports/jira_raport_20260521.csv',
            '/root/out.csv',
        ]);

        self::assertSame(1, $stdout['exitCode']);
        self::assertSame('', $stdout['output']);
        self::assertStringContainsString('Cannot write output CSV: /root/out.csv', $stderr['output']);
        self::assertStringNotContainsString('Warning:', $stderr['output']);
    }

    /**
     * @param list<string> $argv
     * @return array{array{exitCode:int, output:string}, array{output:string}}
     */
    private function runCommand(
        array $argv,
        ?JiraApiConfigResolver $configResolver = null,
        ?Closure $apiEntriesProvider = null,
        ?Closure $httpClientProvider = null,
    ): array {
        $command = new JiraTimesheetCommand(
            configResolver: $configResolver ?? new JiraApiConfigResolver(new SymfonyDotEnvLoader(), []),
            apiEntriesProvider: $apiEntriesProvider,
            httpClientProvider: $httpClientProvider,
        );
        $application = new Application('jira-timesheet');
        $application->addCommand($command);
        $application->setDefaultCommand((string) $command->getName(), true);

        $application->setAutoExit(false);

        $tester = new ApplicationTester($application);
        $exitCode = $tester->run($this->commandInput($argv), ['capture_stderr_separately' => true]);

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

    /**
     * @param list<string> $argv
     * @return array<string, string|bool>
     */
    private function commandInput(array $argv): array
    {
        $arguments = array_slice($argv, 1);

        if ($arguments === ['--help'] || $arguments === ['-h']) {
            return ['--help' => true];
        }

        if (($arguments[0] ?? null) === 'api') {
            return ['mode' => 'api'] + $this->apiOptions(array_slice($arguments, 1));
        }

        $input = [];

        if (isset($arguments[0])) {
            $input['mode'] = $arguments[0];
        }

        if (isset($arguments[1])) {
            $input['output'] = $arguments[1];
        }

        return $input;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private function apiOptions(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $index => $indexValue) {
            $argument = $indexValue;

            if (!str_starts_with($argument, '--')) {
                continue;
            }

            $name = substr($argument, 2);

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            } else {
                $value = $arguments[$index + 1] ?? '';
            }

            $options['--' . $name] = $value;
        }

        return $options;
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);

        $rows = [];

        while (($row = fgetcsv($handle, separator: ',', enclosure: '"', escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => $cell ?? '', $row);
        }

        fclose($handle);

        return $rows;
    }
}

final class SequentialPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return \array_shift($this->responses) ?? new Response(500, [], '{"errorMessages":["unexpected request"]}');
    }
}
