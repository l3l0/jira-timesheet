<?php

declare(strict_types=1);

namespace JiraTimesheet\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    #[Test]
    public function it_runs_on_php_85_with_phpunit_autoloaded(): void
    {
        self::assertTrue(class_exists(TestCase::class));
        self::assertGreaterThanOrEqual(80500, \PHP_VERSION_ID);
        self::assertLessThan(80600, \PHP_VERSION_ID);
    }
}
