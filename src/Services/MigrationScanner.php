<?php

namespace MigrationPreflight\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationScanner
{
    public function getPendingMigrations(): array
    {
        if (!Schema::hasTable('migrations')) {
            $ran = [];
        } else {
            $ran = DB::table('migrations')->pluck('migration')->toArray();
        }

        $ignored = config('preflight.ignore.migrations', []);
        $files = glob(database_path('migrations/*.php')) ?: [];

        return collect($files)
            ->map(fn($file) => basename($file, '.php'))
            ->reject(fn($name) => in_array($name, $ran) || in_array($name, $ignored) || in_array($name . '.php', $ignored))
            ->values()
            ->toArray();
    }
}