<?php

declare(strict_types=1);

namespace JiraTimesheet\Console;

use Closure;
use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Config\JiraApiConfigResolver;
use JiraTimesheet\Jira\Api\JiraHttpClient;
use JiraTimesheet\Jira\Api\JiraHttpClientFactory;
use JiraTimesheet\Jira\Api\JiraWorklogProvider;
use JiraTimesheet\Jira\WorklogEntry;
use JiraTimesheet\Report\DailySummaryAggregator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class JiraDailySummaryCommand extends Command
{
    /**
     * @param null|Closure(JiraApiConfig): list<WorklogEntry> $apiEntriesProvider
     * @param null|Closure(JiraApiConfig): JiraHttpClient $httpClientProvider
     */
    public function __construct(
        private readonly DailySummaryAggregator $aggregator = new DailySummaryAggregator(),
        private readonly DailySummaryTableRenderer $renderer = new DailySummaryTableRenderer(),
        private readonly JiraApiConfigResolver $configResolver = new JiraApiConfigResolver(),
        private readonly ?Closure $apiEntriesProvider = null,
        private readonly ?Closure $httpClientProvider = null,
    ) {
        parent::__construct('api:daily-summary');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Print daily Jira Cloud API worklog totals and the total for the selected period.')
            ->setHelp($this->usage())
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Path to a .env file.')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Jira Cloud base URL.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Jira user email.')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Jira API token.')
            ->addOption('api-token', null, InputOption::VALUE_REQUIRED, 'Jira API token.')
            ->addOption('jql', null, InputOption::VALUE_REQUIRED, 'Jira issue search JQL.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date in YYYY-MM-DD format.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date in YYYY-MM-DD format.')
            ->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Report timezone.')
            ->addUsage('--from YYYY-MM-DD --to YYYY-MM-DD [--jql JQL]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->configResolver->resolve($this->apiArguments($input), \getcwd() ?: '.', requireOutput: false);
            $rows = $this->aggregator->aggregate($this->apiEntries($config));

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

        return (new JiraWorklogProvider($this->httpClient($config)))->read($config);
    }

    private function httpClient(JiraApiConfig $config): JiraHttpClient
    {
        $provider = $this->httpClientProvider;

        return $provider === null ? JiraHttpClientFactory::create($config) : $provider($config);
    }

    /**
     * @return list<string>
     */
    private function apiArguments(InputInterface $input): array
    {
        $arguments = [];

        foreach (['env', 'base-url', 'email', 'token', 'api-token', 'jql', 'from', 'to', 'timezone'] as $name) {
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
  jira-timesheet api:daily-summary --from YYYY-MM-DD --to YYYY-MM-DD [--jql JQL]

Jira API configuration:
  Prefer .env or environment variables: JIRA_BASE_URL, JIRA_EMAIL, JIRA_API_TOKEN, JIRA_JQL, JIRA_TIMEZONE.
  CLI overrides: --env, --base-url, --email, --token, --jql, --from, --to, --timezone.

USAGE;
    }
}
