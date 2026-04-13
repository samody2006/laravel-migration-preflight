<?php

namespace MigrationPreflight;

use Illuminate\Support\ServiceProvider;
use MigrationPreflight\Commands\PreflightCommand;

class MigrationPreflightServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/preflight.php',
            'preflight'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/preflight.php' => config_path('preflight.php'),
        ], 'preflight-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PreflightCommand::class,
            ]);
        }
    }
}