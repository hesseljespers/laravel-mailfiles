<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Melloww\MailFiles\Commands\InspectMailCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MailFilesServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('mailfiles')
            ->hasConfigFile()
            ->hasCommand(InspectMailCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(MailFileReader::class, function ($app): MailFileReader {
            $config = $app['config'];

            /** @var string $driver */
            $driver = $config->get('mailfiles.eml.driver', 'native');

            $options = new ParseOptions(
                extractNestedMessages: (bool) $config->get('mailfiles.attachments.extract_nested', true),
                maxDepth: (int) $config->get('mailfiles.attachments.max_depth', 5),
            );

            return new MailFileReader(emlDriver: $driver, options: $options);
        });

        $this->app->alias(MailFileReader::class, 'mailfiles');
    }
}
