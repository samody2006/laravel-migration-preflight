<?php

namespace MigrationPreflight\Services;

use Illuminate\Support\Facades\Schema;

class SchemaInspector
{
    public function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    public function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
}