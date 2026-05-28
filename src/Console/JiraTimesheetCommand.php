<?php

declare(strict_types=1);

namespace JiraTimesheet\Console;

use Closure;
use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Config\JiraApiConfigResolver;
use JiraTimesheet\Jira\Api\JiraApiWorklogReader;
use JiraTimesheet\Jira\Api\JiraBasicAuthHttpClient;
use JiraTimesheet\Jira\Api\JiraIssueSource;
use JiraTimesheet\Jira\JiraCsvReader;
use JiraTimesheet\Jira\WorklogEntry;
use JiraTimesheet\Report\TimesheetAggregator;
use JiraTimesheet\Report\TimesheetCsvWriter;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class JiraTimesheetCommand extends Command
{
    /**
     * @param null|Closure(JiraApiConfig): list<WorklogEntry> $apiEntriesProvider
     */
    public function __construct(
        private readonly JiraCsvReader $reader = new JiraCsvReader(),
        private readonly TimesheetAggregator $aggregator = new TimesheetAggregator(),
        private readonly TimesheetCsvWriter $writer = new TimesheetCsvWriter(),
        private readonly TimesheetTableRenderer $renderer = new TimesheetTableRenderer(),
        private readonly JiraApiConfigResolver $configResolver = new JiraApiConfigResolver(),
        private readonly ?Closure $apiEntriesProvider = null,
    ) {
        parent::__construct('jira-timesheet');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate daily Jira timesheet reports from CSV exports or Jira Cloud API worklogs.')
            ->setHelp($this->usage())
            ->addArgument('mode', InputArgument::OPTIONAL, 'CSV input path or "api" for Jira Cloud API mode.')
            ->addArgument('output', InputArgument::OPTIONAL, 'Output CSV path for CSV mode.')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Path to a .env file.')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Jira Cloud base URL.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Jira user email.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Jira API token.')
            ->addOption('api-token', null, InputOption::VALUE_REQUIRED, 'Jira API token.')
            ->addOption('jql', null, InputOption::VALUE_REQUIRED, 'Jira issue search JQL.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date in YYYY-MM-DD format.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date in YYYY-MM-DD format.')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Report timezone.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output CSV path for API mode.')
            ->addUsage('input.csv output.csv')
            ->addUsage('api --from YYYY-MM-DD --to YYYY-MM-DD --output output.csv [--jql JQL]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->argument($input, 'mode');

        if ($mode === null) {
            $this->errorOutput($output)->write($this->usage());

            return self::INVALID;
        }

        if ($mode === 'api') {
            return $this->runApiMode($input, $output);
        }

        $outputPath = $this->argument($input, 'output');

        if ($outputPath === null) {
            $this->errorOutput($output)->write($this->usage());

            return self::INVALID;
        }

        try {
            $entries = $this->reader->readIterable($mode);
            $rows = $this->aggregator->aggregate($entries);

            $this->writer->write($outputPath, $rows);
            $output->write($this->renderer->render($rows));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->errorOutput($output)->writeln($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function runApiMode(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configResolver->resolve($this->apiArguments($input), \getcwd() ?: '.');
            $entries = $this->apiEntries($config);
            $rows = $this->aggregator->aggregate($entries);

            $this->writer->write($config->output, $rows);
            $output->write($this->renderer->render($rows));

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->errorOutput($output)->writeln($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return list<WorklogEntry>
     */
    private function apiEntries(JiraApiConfig $config): array
    {
        $provider = $this->apiEntriesProvider;

        if ($provider !== null) {
            return $provider($config);
        }

        $httpClient = new JiraBasicAuthHttpClient($config);
        $issues = (new JiraIssueSource($httpClient))->search($config->jql);

        return (new JiraApiWorklogReader($httpClient))->read($issues, $config->from, $config->to, $config->timezone);
    }

    private function argument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $value = \trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function apiArguments(InputInterface $input): array
    {
        $arguments = [];

        foreach (['env', 'base-url', 'email', 'token', 'api-token', 'jql', 'from', 'to', 'timezone', 'output'] as $name) {
            $value = $input->getOption($name);

            if (!\is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }

            $value = \trim((string) $value);

            if ($value === '') {
                continue;
            }

            $arguments[] = '--' . $name;
            $arguments[] = $value;
        }

        return $arguments;
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    private function usage(): string
    {
        return <<<'USAGE'
Usage:
  jira-timesheet input.csv output.csv
  jira-timesheet api --from YYYY-MM-DD --to YYYY-MM-DD --output output.csv [--jql JQL]

Jira API configuration:
  Prefer .env or environment variables: JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN, JIRA_JQL, JIRA_TIMEZONE.
  CLI overrides: --env, --base-url, --email, --token, --jql, --from, --to, --timezone, --output.

USAGE;
    }
}
