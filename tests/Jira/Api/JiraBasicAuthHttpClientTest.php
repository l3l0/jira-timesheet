<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira\Api;

use JiraTimesheet\Config\JiraApiConfig;
use JiraTimesheet\Jira\Api\JiraApiException;
use JiraTimesheet\Jira\Api\JiraBasicAuthHttpClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class JiraBasicAuthHttpClientTest extends TestCase
{
    #[Test]
    public function it_sends_authenticated_json_requests_through_psr18(): void
    {
        $psr18Client = new RecordingPsr18Client(new Response(200, [], '{"accountId":"abc-123"}'));
        $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

        $payload = $client->request('POST', '/rest/api/3/search/jql', ['jql' => 'project = PAR']);

        self::assertSame(['accountId' => 'abc-123'], $payload);
        self::assertNotNull($psr18Client->request);
        self::assertSame('POST', $psr18Client->request->getMethod());
        self::assertSame('https://example.atlassian.net/rest/api/3/search/jql', (string) $psr18Client->request->getUri());
        self::assertSame('application/json', $psr18Client->request->getHeaderLine('Accept'));
        self::assertSame('application/json', $psr18Client->request->getHeaderLine('Content-Type'));
        self::assertSame(
            'Basic ' . \base64_encode('user@example.com:super-secret-token'),
            $psr18Client->request->getHeaderLine('Authorization'),
        );
        self::assertSame('{"jql":"project = PAR"}', (string) $psr18Client->request->getBody());
    }

    #[Test]
    public function it_decodes_empty_success_responses_as_empty_arrays(): void
    {
        $psr18Client = new RecordingPsr18Client(new Response(204, [], ''));
        $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

        self::assertSame([], $client->request('GET', '/rest/api/3/myself'));
    }

    #[Test]
    public function it_reports_http_errors_without_leaking_token(): void
    {
        $psr18Client = new RecordingPsr18Client(
            new Response(401, [], '{"errorMessages":["Bad token super-secret-token"]}'),
        );
        $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

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
            $psr18Client = new RecordingPsr18Client(
                new Response($statusCode, [], '{"errorMessages":["Jira refused the request"]}'),
            );
            $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

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
        $psr18Client = new RecordingPsr18Client(new Response(200, [], 'not-json super-secret-token'));
        $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

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
    public function it_wraps_psr18_client_errors_without_leaking_token(): void
    {
        $basicSecret = \base64_encode('user@example.com:super-secret-token');
        $authorization = 'Basic ' . $basicSecret;
        $psr18Client = new FailingPsr18Client(
            'Connection failed for super-secret-token, ' . $basicSecret . ' and ' . $authorization,
        );
        $client = new JiraBasicAuthHttpClient($this->config(), $psr18Client);

        try {
            $client->request('GET', '/rest/api/3/myself');
            self::fail('Expected Jira API exception.');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('Jira API transport failed for GET /rest/api/3/myself', $exception->getMessage());
            self::assertStringContainsString('********', $exception->getMessage());
            self::assertStringNotContainsString('super-secret-token', $exception->getMessage());
            self::assertStringNotContainsString($basicSecret, $exception->getMessage());
            self::assertStringNotContainsString($authorization, $exception->getMessage());
            self::assertNull($exception->getPrevious());
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

final class RecordingPsr18Client implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->response;
    }
}

final class FailingPsr18Client implements ClientInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new Psr18TransportException($this->message);
    }
}

final class Psr18TransportException extends RuntimeException implements ClientExceptionInterface
{
}
