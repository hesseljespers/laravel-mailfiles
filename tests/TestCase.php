<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Tests;

use Melloww\MailFiles\MailFilesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            MailFilesServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }

    /**
     * Absolute path to a bundled test fixture.
     */
    public static function fixture(string $relativePath): string
    {
        return __DIR__.'/Fixtures/'.ltrim($relativePath, '/');
    }
}
