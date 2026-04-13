<?php

namespace MigrationPreflight\Services;

class ForeignKeyValidator
{
    public function __construct(
        protected SchemaInspector $schema
    ) {}

    public function validate(string $migration): array
    {
        $errors = [];

        $path = database_path("migrations/{$migration}.php");

        if (!file_exists($path)) {
            return ["Migration file not found"];
        }

        $content = file_get_contents($path);

        preg_match('/Schema::create\\([\"\\\'](.*?)["\\\']/', $content, $tableMatch);

        if (!isset($tableMatch[1])) {
            return ["Cannot detect table name"];
        }

        $table = $tableMatch[1];

        preg_match_all('/foreignId\\([\"\\\'](.*?)["\\\']\\)/', $content, $matches);

        foreach ($matches[1] as $column) {
            $referencedTable = str_replace('_id', 's', $column);

            if (!$this->schema->tableExists($referencedTable)) {
                $errors[] = "Missing referenced table '{$referencedTable}' for '{$column}'";
            }
        }

        return $errors;
    }
}