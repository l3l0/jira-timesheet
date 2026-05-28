<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use JiraTimesheet\Config\JiraApiConfig;
use JsonException;
use RuntimeException;

final readonly class JiraBasicAuthHttpClient implements JiraHttpClient
{
    public function __construct(
        private JiraApiConfig $config,
        private HttpTransport $transport = new StreamHttpTransport(),
    ) {
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
            $response = $this->transport->send(
                $method,
                $this->config->baseUrl . $path,
                $this->headers($encodedBody !== null),
                $encodedBody,
            );
        } catch (RuntimeException $exception) {
            throw new JiraApiException(\sprintf(
                'Jira API transport failed for %s %s: %s',
                $method,
                $path,
                $this->maskToken($exception->getMessage()),
            ), previous: $exception);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new JiraApiException(\sprintf(
                'Jira API request failed (%d) for %s %s: %s',
                $response->statusCode,
                $method,
                $path,
                $this->errorMessage($response),
            ));
        }

        if (\trim($response->body) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($response->body, true, flags: \JSON_THROW_ON_ERROR);
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

    private function errorMessage(HttpResponse $response): string
    {
        if (\trim($response->body) === '') {
            return 'empty response body';
        }

        try {
            $decoded = \json_decode($response->body, true, flags: \JSON_THROW_ON_ERROR);
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
