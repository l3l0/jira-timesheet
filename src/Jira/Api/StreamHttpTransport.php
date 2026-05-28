<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use RuntimeException;

final class StreamHttpTransport implements HttpTransport
{
    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = \stream_context_create([
            'http' => [
                'method' => $method,
                'header' => \implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        $responseBody = @\file_get_contents($url, false, $context);

        if ($responseBody === false) {
            $error = \error_get_last();

            throw new RuntimeException($error['message'] ?? 'unknown HTTP transport error');
        }

        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;

        return new HttpResponse($this->statusCode($responseHeaders), $responseBody, $responseHeaders);
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        $statusLine = $headers[0] ?? '';

        if (\preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) === 1) {
            return (int) $matches[1];
        }

        throw new RuntimeException('HTTP response does not contain a status line');
    }
}
