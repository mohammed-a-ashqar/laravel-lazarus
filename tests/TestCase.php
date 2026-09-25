<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Tests;

use Alashqar\Lazarus\LazarusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LazarusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('lazarus.environments', ['testing']);
        $app['config']->set('lazarus.llm.driver', 'fake');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
