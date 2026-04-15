<?php

namespace MigrationPreflight\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use MigrationPreflight\MigrationPreflightServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            MigrationPreflightServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations()
    {
        // Not needed for simple scanner test
    }
}