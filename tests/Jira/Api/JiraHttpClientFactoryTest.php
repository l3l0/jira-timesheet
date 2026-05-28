<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests\Jira\Api;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use JiraTimesheet\Jira\Api\JiraHttpClientFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

final class JiraHttpClientFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_default_symfony_psr18_client_with_preserved_timeout_contract(): void
    {
        $client = JiraHttpClientFactory::defaultPsr18Client();

        self::assertInstanceOf(ClientInterface::class, $client);
        self::assertInstanceOf(Psr18Client::class, $client);
        self::assertSame(['timeout' => 30], JiraHttpClientFactory::defaultSymfonyOptions());
    }

    #[Test]
    public function it_pins_discovery_to_symfony_psr18_and_nyholm_psr17_factories(): void
    {
        self::assertInstanceOf(Psr18Client::class, Psr18ClientDiscovery::find());
        self::assertInstanceOf(Psr17Factory::class, Psr17FactoryDiscovery::findRequestFactory());
        self::assertInstanceOf(Psr17Factory::class, Psr17FactoryDiscovery::findStreamFactory());
    }
}
