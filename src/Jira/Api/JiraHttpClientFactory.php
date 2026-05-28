<?php

declare(strict_types=1);

namespace JiraTimesheet\Jira\Api;

use JiraTimesheet\Config\JiraApiConfig;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final readonly class JiraHttpClientFactory
{
    public static function create(JiraApiConfig $config, ?ClientInterface $client = null): JiraHttpClient
    {
        return new JiraBasicAuthHttpClient($config, $client);
    }

    public static function defaultPsr18Client(): ClientInterface
    {
        return new Psr18Client(HttpClient::create(self::defaultSymfonyOptions()));
    }

    /**
     * @return array{timeout:int}
     */
    public static function defaultSymfonyOptions(): array
    {
        return ['timeout' => 30];
    }
}
