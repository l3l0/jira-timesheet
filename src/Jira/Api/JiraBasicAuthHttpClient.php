<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use Http\Discovery\Psr17FactoryDiscovery;
use JiraTimesheet\Config\JiraApiConfig;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class JiraBasicAuthHttpClient implements JiraHttpClient
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private JiraApiConfig $config,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? JiraHttpClientFactory::defaultPsr18Client();
        $this->requestFactory = $this->client instanceof RequestFactoryInterface
            ? $this->client
            : Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $this->client instanceof StreamFactoryInterface
            ? $this->client
            : Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * @param null|array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $method = \strtoupper($method);
        $path = $this->normalizePath($path);
        $encodedBody = $this->encodeBody($body, $method, $path);

        try {
            $response = $this->client->sendRequest($this->psrRequest($method, $path, $encodedBody));
        } catch (ClientExceptionInterface $exception) {
            throw new JiraApiException(\sprintf(
                'Jira API transport failed for %s %s: %s',
                $method,
                $path,
                $this->maskToken($exception->getMessage()),
            ));
        }

        $statusCode = $response->getStatusCode();
        $responseBody = $this->responseBody($response);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new JiraApiException(\sprintf(
                'Jira API request failed (%d) for %s %s: %s',
                $statusCode,
                $method,
                $path,
                $this->errorMessage($responseBody),
            ));
        }

        if (\trim($responseBody) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($responseBody, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JiraApiException(\sprintf(
                'Invalid JSON response from Jira API for %s %s',
                $method,
                $path,
            ), previous: $exception);
        }

        if (!\is_array($decoded)) {
            throw new JiraApiException(\sprintf(
                'Invalid JSON response from Jira API for %s %s: expected object or array',
                $method,
                $path,
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function normalizePath(string $path): string
    {
        return '/' . \ltrim($path, '/');
    }

    /**
     * @param null|array<string, mixed> $body
     */
    private function encodeBody(?array $body, string $method, string $path): ?string
    {
        if ($body === null) {
            return null;
        }

        try {
            return \json_encode($body, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JiraApiException(\sprintf(
                'Cannot encode Jira API request body for %s %s',
                $method,
                $path,
            ), previous: $exception);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $hasBody): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . \base64_encode($this->config->email . ':' . $this->config->apiToken),
        ];

        if ($hasBody) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function psrRequest(string $method, string $path, ?string $body): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $this->config->baseUrl . $path);

        foreach ($this->headers($body !== null) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        return $request;
    }

    private function responseBody(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }

    private function errorMessage(string $body): string
    {
        if (\trim($body) === '') {
            return 'empty response body';
        }

        try {
            $decoded = \json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'non-JSON error response';
        }

        if (\is_array($decoded) && isset($decoded['errorMessages']) && \is_array($decoded['errorMessages'])) {
            $messages = \array_values(\array_filter($decoded['errorMessages'], \is_string(...)));

            if ($messages !== []) {
                return $this->maskToken(\implode('; ', $messages));
            }
        }

        return 'Jira returned an error response';
    }

    private function maskToken(string $message): string
    {
        return \str_replace(
            [
                $this->config->apiToken,
                \base64_encode($this->config->email . ':' . $this->config->apiToken),
            ],
            $this->config->maskedApiToken(),
            $message,
        );
    }
}
