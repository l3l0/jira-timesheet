<?php

declare(strict_types=1);

namespace JiraTimesheet\Config;

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;

final class SymfonyDotEnvLoader
{
    /**
     * @return array<string, string>
     */
    public function load(string $path): array
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new ConfigurationException(\sprintf('Configured .env file is not readable: %s', $path));
        }

        $contents = \file_get_contents($path);

        if ($contents === false) {
            throw new ConfigurationException(\sprintf('Configured .env file is not readable: %s', $path));
        }

        $envSnapshot = $_ENV;
        $serverSnapshot = $_SERVER;

        try {
            /** @var array<string, string> $values */
            $values = (new Dotenv())->parse($contents, $path);
        } catch (FormatException $exception) {
            throw new ConfigurationException(
                \sprintf('Configured .env file is invalid: %s', $path),
                previous: $exception,
            );
        } finally {
            $_ENV = $envSnapshot;
            $_SERVER = $serverSnapshot;
        }

        return $values;
    }
}
