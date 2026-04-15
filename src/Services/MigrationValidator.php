<?php

namespace MigrationPreflight\Services;

use Illuminate\Support\Str;

class MigrationValidator
{
    public function __construct(
        protected SchemaInspector $schema
    ) {}

    public function validate(string $migration): array
    {
        $path = database_path("migrations/{$migration}.php");

        if (!file_exists($path)) {
            return ["Migration file not found"];
        }

        return $this->validateContent(file_get_contents($path));
    }

    public function validateContent(string $content): array
    {
        $errors = [];

        // Detect all tables being created or modified
        preg_match_all('/Schema::(create|table)\([\"\\\'](.*?)["\\\']/', $content, $tableMatches);

        if (empty($tableMatches[2])) {
            return ["Cannot detect table name"];
        }

        foreach ($tableMatches[2] as $index => $table) {
            $type = $tableMatches[1][$index];

            // If it's a 'table' modification, check if it exists
            if ($type === 'table' && config('preflight.checks.missing_tables', true)) {
                if (!$this->schema->tableExists($table)) {
                    $errors[] = "Table '{$table}' does not exist";
                }
            }

            if (config('preflight.checks.missing_columns', true)) {
                // Handle after()
                preg_match_all('/after\([\"\\\'](.*?)["\\\']\)/', $content, $afterMatches);
                foreach ($afterMatches[1] as $column) {
                    if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                        $errors[] = "Column '{$column}' does not exist on table '{$table}' (used in after())";
                    }
                }

                // Handle dropColumn()
                preg_match_all('/dropColumn\([\"\\\'](.*?)["\\\']\)/', $content, $dropMatches);
                foreach ($dropMatches[1] as $column) {
                    if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                        $errors[] = "Column '{$column}' does not exist on table '{$table}' (used in dropColumn())";
                    }
                }

                // Handle renameColumn()
                preg_match_all('/renameColumn\([\"\\\'](.*?)["\\\']\s*,\s*[\"\\\'](.*?)["\\\']\)/', $content, $renameMatches);
                foreach ($renameMatches[1] as $column) {
                    if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                        $errors[] = "Column '{$column}' does not exist on table '{$table}' (used in renameColumn())";
                    }
                }
            }

            // Find foreignId and constrained calls
            // Example: $table->foreignId('user_id')->constrained()
            // Example: $table->foreignId('user_id')->constrained('some_table')
            // Example: $table->foreign('user_id')->references('id')->on('users')

            if (config('preflight.checks.foreign_keys', true)) {
                // Handle foreignId()->constrained()
                preg_match_all('/foreignId\([\"\\\'](.*?)["\\\']\)(?:->constrained\([\"\\\'](.*?)["\\\']\))?/', $content, $foreignIdMatches);

                foreach ($foreignIdMatches[1] as $idx => $column) {
                    $explicitTable = $foreignIdMatches[2][$idx] ?: null;

                    if ($explicitTable) {
                        $referencedTable = $explicitTable;
                    } else {
                        // Guess table name: user_id -> users
                        $referencedTable = Str::plural(str_replace('_id', '', $column));
                    }

                    if (!$this->schema->tableExists($referencedTable)) {
                        $errors[] = "Missing referenced table '{$referencedTable}' for '{$column}'";
                    }
                }

                // Handle foreign()->references()->on()
                preg_match_all('/foreign\([\"\\\'](.*?)["\\\']\)->references\([\"\\\'](.*?)["\\\']\)->on\([\"\\\'](.*?)["\\\']\)/', $content, $foreignOnMatches);

                foreach ($foreignOnMatches[3] as $idx => $referencedTable) {
                    $column = $foreignOnMatches[1][$idx];
                    if (!$this->schema->tableExists($referencedTable)) {
                        $errors[] = "Missing referenced table '{$referencedTable}' for '{$column}'";
                    }
                }
            }
        }

        return $errors;
    }
}