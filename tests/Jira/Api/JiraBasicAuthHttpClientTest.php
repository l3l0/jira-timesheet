<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira\Api;

use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Jira\Api\HttpResponse;
use JiraTimesheet\Jira\Api\HttpTransport;
use JiraTimesheet\Jira\Api\JiraApiException;
use JiraTimesheet\Jira\Api\JiraBasicAuthHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JiraBasicAuthHttpClientTest extends TestCase
{
    #[Test]
    public function it_sends_authenticated_json_requests(): void
    {
        $transport = new RecordingHttpTransport(new HttpResponse(200, '{"accountId":"abc-123"}'));
        $client = new JiraBasicAuthHttpClient($this->config(), $transport);

        $payload = $client->request('POST', '/rest/api/3/search/jql', ['jql' => 'project = PAR']);

        self::assertSame(['accountId' => 'abc-123'], $payload);
        self::assertSame('POST', $transport->method);
        self::assertSame('https://example.atlassian.net/rest/api/3/search/jql', $transport->url);
        self::assertSame('application/json', $transport->headers['Accept']);
        self::assertSame('application/json', $transport->headers['Content-Type']);
        self::assertSame('Basic ' . \base64_encode('user@example.com:super-secret-token'), $transport->headers['Authorization']);
        self::assertSame('{"jql":"project = PAR"}', $transport->body);
    }

    #[Test]
    public function it_decodes_empty_success_responses_as_empty_arrays(): void
    {
        $transport = new RecordingHttpTransport(new HttpResponse(204, ''));
        $client = new JiraBasicAuthHttpClient($this->config(), $transport);

        self::assertSame([], $client->request('GET', '/rest/api/3/myself'));
    }

    #[Test]
    public function it_reports_http_errors_without_leaking_token(): void
    {
        $transport = new RecordingHttpTransport(
            new HttpResponse(401, '{"errorMessages":["Bad token super-secret-token"]}'),
        );
        $client = new JiraBasicAuthHttpClient($this->config(), $transport);

        try {
            $client->request('GET', '/rest/api/3/myself');
            self::fail('Expected Jira API exception.');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('Jira API request failed (401)', $exception->getMessage());
            self::assertStringContainsString('Bad token ********', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-token', $exception->getMessage());
        }
    }

    #[Test]
    public function it_reports_common_jira_http_error_statuses(): void
    {
        foreach ([403, 404, 429] as $statusCode) {
            $transport = new RecordingHttpTransport(
                new HttpResponse($statusCode, '{"errorMessages":["Jira refused the request"]}'),
            );
            $client = new JiraBasicAuthHttpClient($this->config(), $transport);

            try {
                $client->request('GET', '/rest/api/3/myself');
                self::fail('Expected Jira API exception.');
            } catch (JiraApiException $exception) {
                self::assertStringContainsString(\sprintf('Jira API request failed (%d)', $statusCode), $exception->getMessage());
                self::assertStringContainsString('Jira refused the request', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_reports_invalid_json_without_dumping_response_body(): void
    {
        $transport = new RecordingHttpTransport(new HttpResponse(200, 'not-json super-secret-token'));
        $client = new JiraBasicAuthHttpClient($this->config(), $transport);

        try {
            $client->request('GET', '/rest/api/3/myself');
            self::fail('Expected Jira API exception.');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('Invalid JSON response from Jira API', $exception->getMessage());
            self::assertStringNotContainsString('not-json', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-token', $exception->getMessage());
        }
    }

    #[Test]
    public function it_wraps_transport_errors_without_leaking_token(): void
    {
        $basicSecret = \base64_encode('user@example.com:super-secret-token');
        $transport = new FailingHttpTransport('Connection failed for super-secret-token and Basic ' . $basicSecret);
        $client = new JiraBasicAuthHttpClient($this->config(), $transport);

        try {
            $client->request('GET', '/rest/api/3/myself');
            self::fail('Expected Jira API exception.');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('Jira API transport failed for GET /rest/api/3/myself', $exception->getMessage());
            self::assertStringContainsString('********', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-token', $exception->getMessage());
            self::assertStringNotContainsString($basicSecret, $exception->getMessage());
        }
    }

    private function config(): JiraApiConfig
    {
        return new JiraApiConfig(
            'https://example.atlassian.net',
            'user@example.com',
            'super-secret-token',
            'project = PAR',
            '2026-05-01',
            '2026-06-01',
            'Europe/Warsaw',
            '/tmp/out.csv',
        );
    }
}

final class RecordingHttpTransport implements HttpTransport
{
    public string $method = '';
    public string $url = '';

    /** @var array<string, string> */
    public array $headers = [];
    public ?string $body = null;

    public function __construct(private readonly HttpResponse $response)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

        return $this->response;
    }
}

final class FailingHttpTransport implements HttpTransport
{
    public function __construct(private readonly string $message)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        throw new RuntimeException($this->message);
    }
}
