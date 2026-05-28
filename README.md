# Jira Timesheet FlowPHP

A PHP/FlowPHP CLI tool that turns a Jira CSV export or Jira Cloud API worklogs into a daily time report. The CLI is built on Symfony Console, and API mode reads worklogs based on `worklog.started`.

## Requirements

- Docker
- Docker Compose
- Make

The application runtime runs in a PHP 8.5 container. The currently installed runtime dependencies include `flow-php/etl`, Symfony Console/Dotenv, Symfony HttpClient, PSR HTTP contracts, `nyholm/psr7`, and `php-http/discovery`.

## Installation and Tests

```bash
make build
make composer_install
make test
```

## Generating a Report

### From a CSV Export

```bash
make report INPUT=raports/jira_raport_20260521.csv OUTPUT=/tmp/jira_grouped.csv
```

`INPUT` is the Jira CSV export. `OUTPUT` is the path where the generated CSV file should be written. The `/tmp` directory is mounted into the container, so `/tmp/jira_grouped.csv` is available on the host.

### From the Jira Cloud API

Copy `.env.example` to `.env` and fill in the values:

```bash
cp .env.example .env
```

Set the following values in `.env`:

```text
JIRA_BASE_URL=https://your-jira.atlassian.net
JIRA_EMAIL=your.email@example.com
JIRA_API_TOKEN=replace-with-api-token
JIRA_JQL='project = "ProjectName" AND worklogDate >= "2026-05-01" AND worklogDate < "2026-06-01"'
JIRA_TIMEZONE=Europe/Warsaw
```

Keep the token in `.env` or environment variables. Do not pass it through `--token` if you do not want the secret to appear in your shell history. Values with spaces, such as `JIRA_JQL`, must be quoted using Symfony Dotenv syntax.

API mode CLI contract:

```bash
make report_api FROM=2026-05-01 TO=2026-06-01 OUTPUT=/tmp/jira_api_grouped.csv
```

CLI arguments take precedence over environment variables, and environment variables take precedence over `.env`. If `JIRA_FROM`, `JIRA_TO`, or `JIRA_OUTPUT` are set in `.env`, you can omit the corresponding `FROM`, `TO`, and `OUTPUT` Make variables.

API mode searches for issues with JQL, fetches worklogs per issue, filters them by `started` in the `[FROM, TO)` range, and by default keeps only the worklogs of the current token user. The `--issue`, `--issues-file`, and all-visible-authors report modes are not part of the current version.

## HTTP Client

Jira API mode uses PSR-18 as the HTTP client contract. The default runtime client is Symfony HttpClient adapted through `Symfony\Component\HttpClient\Psr18Client` with a 30-second timeout.

Maintainers can pass any `Psr\Http\Client\ClientInterface` to `JiraBasicAuthHttpClient`. PSR-7/PSR-17 request creation is handled internally through `php-http/discovery`, pinned in Composer to the installed Symfony/Nyholm implementations.

## CSV Format

The generated report has the following columns:

```text
date,row_type,issue_key,summary,hours,seconds
```

Rules:

- `row_type=task` means the time logged for a specific issue on a given day.
- `row_type=total` means the daily total; `issue_key` is empty and `summary=TOTAL`.
- `hours` always has two decimal places, for example `2.00`.
- Rows are sorted by ascending date and then by `issue_key`; the daily total is placed at the end of each day group.

Example for `raports/jira_raport_20260521.csv`:

```text
2026-05-21,task,PAR-1,...,14.00,50400
2026-05-21,task,PAR-2,...,2.00,7200
2026-05-21,total,,TOTAL,16.00,57600
```
