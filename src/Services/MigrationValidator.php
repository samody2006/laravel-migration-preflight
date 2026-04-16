<?php

namespace MigrationPreflight\Services;

use Illuminate\Support\Str;

class MigrationValidator
{
    public function __construct(
        protected SchemaInspector $schema,
        protected ?ConstraintParser $constraintParser = null
    ) {
        $this->constraintParser = $this->constraintParser ?? new ConstraintParser();
    }

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
            return [["message" => "Cannot detect table name", "lineNumber" => 1]];
        }

        foreach ($tableMatches[2] as $index => $table) {
            $type = $tableMatches[1][$index];

            // If it's a 'table' modification, check if it exists
            if ($type === 'table' && config('preflight.checks.missing_tables', true)) {
                if (!$this->schema->tableExists($table)) {
                    $errors[] = [
                        "message" => "Table '{$table}' does not exist",
                        "lineNumber" => $this->findTableLineNumber($content, $table),
                        "type" => "missing_table",
                    ];
                }
            }

            if (config('preflight.checks.missing_columns', true)) {
                $errors = array_merge($errors, $this->validateColumnOperations($content, $table));
            }

            if (config('preflight.checks.foreign_keys', true)) {
                $errors = array_merge($errors, $this->validateForeignKeys($content, $table));
            }

            if (config('preflight.checks.index_constraints', true)) {
                $errors = array_merge($errors, $this->validateIndexConstraints($content, $table));
            }

            if (config('preflight.checks.unique_constraints', true)) {
                $errors = array_merge($errors, $this->validateUniqueConstraints($content, $table));
            }
        }

        return $errors;
    }

    /**
     * Validate column operations (after, dropColumn, renameColumn, change)
     */
    protected function validateColumnOperations(string $content, string $table): array
    {
        $errors = [];
        $lines = explode("\n", $content);

        // Handle after()
        preg_match_all('/after\([\"\\\'](.*?)["\\\']\)/', $content, $afterMatches, PREG_OFFSET_CAPTURE);
        foreach ($afterMatches[1] as $column_match) {
            $column = $column_match[0];
            if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in after())",
                    "lineNumber" => $this->findLineNumberByContent($content, $column_match[1]),
                    "type" => "missing_column",
                ];
            }
        }

        // Handle dropColumn()
        preg_match_all('/dropColumn\([\"\\\'](.*?)["\\\']\)/', $content, $dropMatches, PREG_OFFSET_CAPTURE);
        foreach ($dropMatches[1] as $column_match) {
            $column = $column_match[0];
            if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in dropColumn())",
                    "lineNumber" => $this->findLineNumberByContent($content, $column_match[1]),
                    "type" => "missing_column",
                ];
            }
        }

        // Handle renameColumn()
        preg_match_all('/renameColumn\([\"\\\'](.*?)["\\\']\s*,\s*[\"\\\'](.*?)["\\\']\)/', $content, $renameMatches, PREG_OFFSET_CAPTURE);
        foreach ($renameMatches[1] as $idx => $column_match) {
            $column = $column_match[0];
            if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in renameColumn())",
                    "lineNumber" => $this->findLineNumberByContent($content, $column_match[1]),
                    "type" => "missing_column",
                ];
            }
        }

        // Handle change()
        preg_match_all('/change\(\)/', $content, $changeMatches, PREG_OFFSET_CAPTURE);
        foreach ($changeMatches[0] as $change_match) {
            // Find the column name before change()
            $pos = $change_match[1];
            $beforeChange = substr($content, max(0, $pos - 200), 200);
            preg_match('/\$table->(\w+)\s*\(\s*["\'](\w+)["\']/', $beforeChange, $colMatch);
            if (!empty($colMatch[2])) {
                $column = $colMatch[2];
                if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                    $errors[] = [
                        "message" => "Column '{$column}' does not exist on table '{$table}' (used in change())",
                        "lineNumber" => $this->findLineNumberByContent($content, $pos),
                        "type" => "missing_column",
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * Validate foreign key constraints
     */
    protected function validateForeignKeys(string $content, string $table): array
    {
        $errors = [];

        // Handle foreignId()->constrained() - including method chains between them
        // This regex handles cases where constrained() is called after foreignId() with optional method chains in between
        preg_match_all('/foreignId\(["\'](\w+)["\']\)(?:[^;]*?->\s*)?constrained\s*\(\s*["\']?(\w*)["\']?\s*\)/s', $content, $foreignIdMatches, PREG_OFFSET_CAPTURE);

        foreach ($foreignIdMatches[1] as $idx => $column_match) {
            $column = $column_match[0];
            $explicitTable = !empty($foreignIdMatches[2][$idx][0]) ? $foreignIdMatches[2][$idx][0] : null;

            if ($explicitTable) {
                $referencedTable = $explicitTable;
            } else {
                // Guess table name: user_id -> users
                $referencedTable = Str::plural(str_replace('_id', '', $column));
            }

            if (!$this->schema->tableExists($referencedTable)) {
                $errors[] = [
                    "message" => "Missing referenced table '{$referencedTable}' for '{$column}'",
                    "lineNumber" => $this->findLineNumberByContent($content, $column_match[1]),
                    "type" => "foreign_key",
                ];
            }
        }

        // Handle foreign()->references()->on()
        preg_match_all('/foreign\(["\'](\w+)["\']\)->references\(["\'](\w+)["\']\)->on\(["\'](\w+)["\']\)/', $content, $foreignOnMatches, PREG_OFFSET_CAPTURE);

        foreach ($foreignOnMatches[3] as $idx => $refTable_match) {
            $referencedTable = $refTable_match[0];
            $column = $foreignOnMatches[1][$idx][0];
            if (!$this->schema->tableExists($referencedTable)) {
                $errors[] = [
                    "message" => "Missing referenced table '{$referencedTable}' for '{$column}'",
                    "lineNumber" => $this->findLineNumberByContent($content, $refTable_match[1]),
                    "type" => "foreign_key",
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate index constraints
     */
    protected function validateIndexConstraints(string $content, string $table): array
    {
        $errors = [];
        
        // Get explicit constraints like $table->index(['col1', 'col2'])
        $constraints = $this->constraintParser->parseIndexConstraints($content);
        foreach ($constraints as $constraint) {
            if ($constraint['type'] === 'index' || $constraint['type'] === 'fullText' || $constraint['type'] === 'spatialIndex') {
                foreach ($constraint['columns'] as $column) {
                    if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                        $errors[] = [
                            "message" => "Column '{$column}' does not exist on table '{$table}' (used in {$constraint['type']}())",
                            "lineNumber" => $constraint['lineNumber'],
                            "type" => "index_constraint",
                        ];
                    }
                }
            }
        }

        // Also detect chained ->index() calls on column definitions
        // e.g., $table->string('email')->index()
        preg_match_all('/\$table->(\w+)\s*\(\s*["\'](\w+)["\'].*?\)->(index|fullText|spatialIndex)\s*\(\s*\)/', $content, $chainedMatches, PREG_OFFSET_CAPTURE);
        
        foreach ($chainedMatches[2] as $idx => $column_match) {
            $column = $column_match[0];
            $constraintType = $chainedMatches[3][$idx][0];
            
            if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in {$constraintType}())",
                    "lineNumber" => $this->findLineNumberByContent($content, $column_match[1]),
                    "type" => "index_constraint",
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate unique constraints
     */
    protected function validateUniqueConstraints(string $content, string $table): array
    {
        $errors = [];
        $constraints = $this->constraintParser->parseIndexConstraints($content);

        foreach ($constraints as $constraint) {
            if ($constraint['type'] === 'unique') {
                foreach ($constraint['columns'] as $column) {
                    if ($this->schema->tableExists($table) && !$this->schema->columnExists($table, $column)) {
                        $errors[] = [
                            "message" => "Column '{$column}' does not exist on table '{$table}' (used in unique())",
                            "lineNumber" => $constraint['lineNumber'],
                            "type" => "unique_constraint",
                        ];
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Find line number of a table definition
     */
    protected function findTableLineNumber(string $content, string $table): int
    {
        $lines = explode("\n", $content);
        foreach ($lines as $idx => $line) {
            if (strpos($line, "Schema::") !== false && strpos($line, $table) !== false) {
                return $idx + 1;
            }
        }
        return 1;
    }

    /**
     * Convert string offset to line number
     */
    protected function findLineNumberByContent(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }
}