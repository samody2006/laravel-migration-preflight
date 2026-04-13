<?php

namespace MigrationPreflight\Services;

use Illuminate\Support\Facades\DB;

class MigrationScanner
{
    public function getPendingMigrations(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->toArray();

        $files = glob(database_path('migrations/*.php'));

        return collect($files)
            ->map(fn($file) => basename($file, '.php'))
            ->reject(fn($name) => in_array($name, $ran))
            ->values()
            ->toArray();
    }
}