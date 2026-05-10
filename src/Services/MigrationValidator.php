<?php

declare(strict_types=1);

namespace MigrationPreflight\Services;

use Illuminate\Support\Str;

class MigrationValidator
{
    /**
     * Tables created during the current preflight run
     */
    protected array $virtualTables = [];

    /**
     * Columns created during the current preflight run
     * Format: ['table_name' => ['col1', 'col2']]
     */
    protected array $virtualColumns = [];

    /**
     * Track table dependencies (foreign keys)
     * Format: ['table_name' => ['referenced_table1', 'referenced_table2']]
     */
    protected array $dependencies = [];

    /**
     * Track indexes and unique constraints
     * Format: ['table_name' => [['type' => 'unique', 'columns' => ['col1']], ...]]
     */
    protected array $indexes = [];

    public function __construct(
        protected SchemaInspector $schema,
        protected ?ConstraintParser $constraintParser = null
    ) {
        $this->constraintParser = $this->constraintParser ?? new ConstraintParser();
    }

    /**
     * Clear the virtual tables and columns state
     */
    public function clearVirtualState(): void
    {
        $this->virtualTables = [];
        $this->virtualColumns = [];
        $this->dependencies = [];
        $this->indexes = [];
    }

    /**
     * Add a table to the virtual state
     */
    public function addVirtualTable(string $table): void
    {
        if (!in_array($table, $this->virtualTables)) {
            $this->virtualTables[] = $table;
        }
        
        if (!isset($this->virtualColumns[$table])) {
            $this->virtualColumns[$table] = [];
        }
    }

    /**
     * Add a column to the virtual state
     */
    public function addVirtualColumn(string $table, string $column): void
    {
        if (!isset($this->virtualColumns[$table])) {
            $this->virtualColumns[$table] = [];
        }
        
        if (!in_array($column, $this->virtualColumns[$table])) {
            $this->virtualColumns[$table][] = $column;
        }
    }

    /**
     * Check if a table exists (either in schema or virtually created)
     */
    protected function tableExists(string $table): bool
    {
        return in_array($table, $this->virtualTables) || $this->schema->tableExists($table);
    }

    /**
     * Check if a column exists (either in schema or virtually created)
     */
    protected function columnExists(string $table, string $column): bool
    {
        // If the table is virtual and has the column, it exists
        if (isset($this->virtualColumns[$table]) && in_array($column, $this->virtualColumns[$table])) {
            return true;
        }

        // If the table exists in the real schema, check there
        if ($this->schema->tableExists($table)) {
            return $this->schema->columnExists($table, $column);
        }

        return false;
    }

    /**
     * Pre-scan migrations to populate virtual tables
     */
    public function preScan(array $migrations): void
    {
        foreach ($migrations as $migration) {
            $path = database_path("migrations/{$migration}.php");
            if (!file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);
            
            // Detect Schema::create calls for virtual tables
            if (preg_match_all('/Schema\s*::\s*create\s*\(\s*["\'](.*?)["\']/', $content, $matches)) {
                foreach ($matches[1] as $table) {
                    $this->addVirtualTable($table);
                }
            }
        }
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

        // Focus on the up() method as that's what runs during migration
        $upMethodContent = $this->extractUpMethodContent($content);
        $searchContent = $upMethodContent ?: $content;

        // Detect all Schema::create/table blocks
        $blocks = $this->extractSchemaBlocks($searchContent, $upMethodContent ? $this->findUpMethodLine($content) : 1);

        if (empty($blocks)) {
            // If no blocks found, it might be using a different syntax or it's not a standard migration
            // Try a fallback to just detect table names if they exist at all
            preg_match_all('/Schema\s*::\s*(create|table)\s*\(\s*["\'](.*?)["\']/', $content, $tableMatches);
            if (empty($tableMatches[2])) {
                return [["message" => "Cannot detect table name", "lineNumber" => 1]];
            }
        }

        // First pass: register all tables and columns being created/modified in this migration
        foreach ($blocks as $block) {
            $table = $block['table'];
            
            if ($block['type'] === 'create') {
                $this->addVirtualTable($table);
            }
            
            // Track columns created in this block (for subsequent migrations or checks in same block)
            $newColumns = $this->extractNewColumnsInBlock($block['content'], $block['varName']);
            foreach ($newColumns as $column) {
                $this->addVirtualColumn($table, $column);
            }
        }

        $ignoredTables = config('preflight.ignore.tables', []);

        foreach ($blocks as $block) {
            $table = $block['table'];

            if (in_array($table, $ignoredTables)) {
                continue;
            }

            $type = $block['type'];
            $blockContent = $block['content'];
            $startLine = $block['startLine'];
            $varName = $block['varName'];

            // If it's a 'table' modification, check if it exists
            if ($type === 'table' && config('preflight.checks.missing_tables', true)) {
                if (!$this->tableExists($table)) {
                    $errors[] = [
                        "message" => "Table '{$table}' does not exist",
                        "lineNumber" => $startLine,
                        "type" => "missing_table",
                    ];
                }
            }

            // Get columns being created in THIS block (for in-block creation awareness)
            $newColumns = $this->extractNewColumnsInBlock($blockContent, $varName);

            if (config('preflight.checks.missing_columns', true)) {
                $errors = array_merge($errors, $this->validateColumnOperationsInBlock($blockContent, $table, $newColumns, $startLine, $varName));
            }

            if (config('preflight.checks.foreign_keys', true)) {
                $errors = array_merge($errors, $this->validateForeignKeysInBlock($blockContent, $table, $startLine, $varName));
            }

            if (config('preflight.checks.index_constraints', true)) {
                $errors = array_merge($errors, $this->validateIndexConstraintsInBlock($blockContent, $table, $newColumns, $startLine, $varName));
            }

            if (config('preflight.checks.unique_constraints', true)) {
                $errors = array_merge($errors, $this->validateUniqueConstraintsInBlock($blockContent, $table, $newColumns, $startLine, $varName));
            }
        }

        return $errors;
    }

    /**
     * Extract the content of the up() method
     */
    protected function extractUpMethodContent(string $content): ?string
    {
        if (preg_match('/public\s+function\s+up\s*\(\s*\)\s*:\s*void\s*\{(.*?)\}\s*(?:public|protected|private|abstract|\/\*\*|$)/s', $content, $matches)) {
            return $matches[1];
        }
        
        // Simpler fallback for different return type hints or no return type
        if (preg_match('/public\s+function\s+up\s*\(\s*\)\s*\{(.*?)\}\s*(?:public|protected|private|abstract|\/\*\*|$)/s', $content, $matches)) {
            return $matches[1];
        }

        // Final fallback: everything after up() until the end of the file or next method
        if (preg_match('/public\s+function\s+up\s*\(\s*\).*?\{(.*)/s', $content, $matches)) {
            $remaining = $matches[1];
            $openingBracePos = -1; // We are already inside the first brace
            $closingBracePos = $this->findMatchingBrace('{' . $remaining, 0);
            if ($closingBracePos !== -1) {
                return substr($remaining, 0, $closingBracePos - 1);
            }
        }

        return null;
    }

    /**
     * Find the starting line of the up() method
     */
    protected function findUpMethodLine(string $content): int
    {
        if (preg_match('/public\s+function\s+up\s*\(\s*\)/', $content, $match, PREG_OFFSET_CAPTURE)) {
            return $this->findLineNumberByContent($content, $match[0][1]);
        }
        return 1;
    }

    /**
     * Extract individual Schema blocks from the migration content
     */
    protected function extractSchemaBlocks(string $content, int $offsetLine = 1): array
    {
        $blocks = [];
        // More permissive pattern to find the start of a Schema closure
        $pattern = '/Schema\s*::\s*(create|table)\s*\(\s*["\'](.*?)["\']\s*,\s*function\s*\(/i';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $idx => $match) {
                $type = strtolower($matches[1][$idx][0]);
                $table = $matches[2][$idx][0];
                $startPos = $match[1];
                
                // Find the variable name in the closure: function ($table) or function (Blueprint $table)
                // Now also supports 'use ($var)' syntax
                $afterFunctionPos = $startPos + strlen($match[0]);
                $remainingContent = substr($content, $afterFunctionPos);
                
                if (preg_match('/^\s*(?:[\\\\a-zA-Z0-9_]+\s+)?\$(\w+)\s*\)(?:\s*use\s*\([^)]*\))?\s*\{/', $remainingContent, $varMatch)) {
                    $varName = $varMatch[1];
                    $openingBracePos = $afterFunctionPos + strpos($remainingContent, '{');
                    
                    // Find matching closing brace for this block
                    $closingBracePos = $this->findMatchingBrace($content, $openingBracePos);
                    
                    if ($closingBracePos !== -1) {
                        $blockContent = substr($content, $startPos, $closingBracePos - $startPos + 1);
                        $blocks[] = [
                            'type' => $type,
                            'table' => $table,
                            'varName' => $varName,
                            'content' => $blockContent,
                            'startLine' => $offsetLine + $this->findLineNumberByContent($content, $startPos),
                        ];
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * Find matching closing brace
     */
    protected function findMatchingBrace(string $content, int $openingPos): int
    {
        $depth = 0;
        $len = strlen($content);
        for ($i = $openingPos; $i < $len; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /**
     * Extract columns being created in the block
     */
    protected function extractNewColumnsInBlock(string $content, string $varName): array
    {
        $columns = [];

        // Known Laravel column creation methods
        $creationMethods = [
            'string', 'integer', 'boolean', 'decimal', 'float', 'text', 'timestamp', 'date', 'datetime',
            'json', 'jsonb', 'binary', 'uuid', 'ipAddress', 'macAddress', 'char', 'longText', 'mediumText', 'tinyText',
            'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger', 'unsignedMediumInteger', 'unsignedBigInteger',
            'bigInteger', 'smallInteger', 'tinyInteger', 'foreignId', 'increments', 'tinyIncrements', 'smallIncrements',
            'mediumIncrements', 'bigIncrements', 'set', 'enum', 'rememberToken', 'timestamps', 'softDeletes', 'id'
        ];

        // Standard column definitions: $table->string('column_name')
        $pattern = '/\$' . $varName . '\s*->\s*(' . implode('|', $creationMethods) . ')\s*\(\s*["\'](\w+)["\']/';
        if (preg_match_all($pattern, $content, $matches)) {
            if (!empty($matches[2])) {
                $columns = array_merge($columns, $matches[2]);
            }
        }

        // Shortcut methods without column name argument
        if (preg_match('/\$' . $varName . '\s*->\s*id\s*\(/', $content) || preg_match('/\$' . $varName . '\s*->\s*id\s*[;)]/', $content)) {
            $columns[] = 'id';
        }
        if (preg_match('/\$' . $varName . '\s*->\s*timestamps/', $content)) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }
        if (preg_match('/\$' . $varName . '\s*->\s*softDeletes/', $content)) {
            $columns[] = 'deleted_at';
        }
        if (preg_match('/\$' . $varName . '\s*->\s*rememberToken/', $content)) {
            $columns[] = 'remember_token';
        }

        return array_unique($columns);
    }

    /**
     * Validate column operations (after, dropColumn, renameColumn, change)
     */
    protected function validateColumnOperationsInBlock(string $content, string $table, array $newColumns, int $blockStartLine, string $varName): array
    {
        $errors = [];

        // Handle after()
        preg_match_all('/->\s*after\s*\(\s*["\'](.*?)["\']\s*\)/', $content, $afterMatches, PREG_OFFSET_CAPTURE);
        foreach ($afterMatches[1] as $column_match) {
            $column = $column_match[0];
            if ($this->tableExists($table) 
                && !$this->columnExists($table, $column)
                && !in_array($column, $newColumns)
            ) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in after())",
                    "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                    "type" => "missing_column",
                ];
            }
        }

        // Handle dropColumn()
        // Improved to handle single column and array of columns
        preg_match_all('/->\s*dropColumn\s*\((.*?)\)/s', $content, $dropMatches, PREG_OFFSET_CAPTURE);
        foreach ($dropMatches[1] as $args_match) {
            $args = trim($args_match[0]);
            $columns = [];
            
            if (str_starts_with($args, '[') || str_starts_with($args, 'array(')) {
                // Array of columns
                preg_match_all('/["\'](.*?)["\']/', $args, $colMatches);
                $columns = $colMatches[1];
            } else {
                // Single column
                if (preg_match('/["\'](.*?)["\']/', $args, $colMatch)) {
                    $columns = [$colMatch[1]];
                }
            }

            foreach ($columns as $column) {
                if ($this->tableExists($table) 
                    && !$this->schema->columnExists($table, $column)
                    && !in_array($column, $newColumns)
                ) {
                    $errors[] = [
                        "message" => "Column '{$column}' does not exist on table '{$table}' (used in dropColumn())",
                        "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $args_match[1]), "\n"),
                        "type" => "missing_column",
                    ];
                }
            }
        }

        // Handle renameColumn()
        preg_match_all('/->\s*renameColumn\s*\(\s*["\'](.*?)["\']\s*,\s*["\'](.*?)["\']\s*\)/', $content, $renameMatches, PREG_OFFSET_CAPTURE);
        foreach ($renameMatches[1] as $idx => $column_match) {
            $column = $column_match[0];
            if ($this->tableExists($table) 
                && !$this->columnExists($table, $column)
                && !in_array($column, $newColumns)
            ) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in renameColumn())",
                    "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                    "type" => "missing_column",
                ];
            }
        }

        // Handle change()
        preg_match_all('/->\s*change\s*\(\s*\)/', $content, $changeMatches, PREG_OFFSET_CAPTURE);
        foreach ($changeMatches[0] as $change_match) {
            // Find the column name before change()
            $pos = $change_match[1];
            $beforeChange = substr($content, 0, $pos);
            preg_match_all('/\$' . $varName . '\s*->\s*[a-zA-Z0-9_]+\s*\(\s*["\'](\w+)["\']/', $beforeChange, $colMatches);
            if (!empty($colMatches[1])) {
                $column = end($colMatches[1]);
                if ($this->tableExists($table) 
                    && !$this->schema->columnExists($table, $column)
                    && !in_array($column, $newColumns)
                ) {
                    $errors[] = [
                        "message" => "Column '{$column}' does not exist on table '{$table}' (used in change())",
                        "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $pos), "\n"),
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
    protected function validateForeignKeysInBlock(string $content, string $table, int $blockStartLine, string $varName): array
    {
        $errors = [];

        // Handle foreignId()->constrained()
        preg_match_all('/->\s*foreignId\s*\(\s*["\'](\w+)["\']\s*\)(?:[^;]*?->\s*)?constrained\s*\(\s*["\']?(\w*)["\']?\s*\)/s', $content, $foreignIdMatches, PREG_OFFSET_CAPTURE);

        foreach ($foreignIdMatches[1] as $idx => $column_match) {
            $column = $column_match[0];
            $explicitTable = !empty($foreignIdMatches[2][$idx][0]) ? $foreignIdMatches[2][$idx][0] : null;

            if ($explicitTable) {
                $referencedTable = $explicitTable;
            } else {
                // Guess table name: user_id -> users
                $referencedTable = Str::plural(str_replace('_id', '', $column));
            }

            if (!$this->tableExists($referencedTable)) {
                $errors[] = [
                    "message" => "Missing referenced table '{$referencedTable}' for '{$column}'",
                    "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                    "type" => "foreign_key",
                ];
            } else {
                // Record dependency and check for circular reference
                if (config('preflight.checks.circular_dependencies', true)) {
                    if ($this->hasCircularDependency($table, $referencedTable)) {
                        $errors[] = [
                            "message" => "Circular dependency detected: '{$table}' -> '{$referencedTable}' -> ... -> '{$table}'",
                            "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                            "type" => "circular_dependency",
                        ];
                    }
                    $this->recordDependency($table, $referencedTable);
                }
            }
        }

        // Handle foreign()->references()->on()
        preg_match_all('/->\s*foreign\s*\(\s*["\'](\w+)["\']\s*\)\s*->\s*references\s*\(\s*["\'](\w+)["\']\s*\)\s*->\s*on\s*\(\s*["\'](\w+)["\']\s*\)/', $content, $foreignOnMatches, PREG_OFFSET_CAPTURE);

        foreach ($foreignOnMatches[3] as $idx => $refTable_match) {
            $referencedTable = $refTable_match[0];
            $column = $foreignOnMatches[1][$idx][0];
            
            if (!$this->tableExists($referencedTable)) {
                $errors[] = [
                    "message" => "Missing referenced table '{$referencedTable}' for '{$column}'",
                    "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $refTable_match[1]), "\n"),
                    "type" => "foreign_key",
                ];
            } else {
                // Record dependency and check for circular reference
                if (config('preflight.checks.circular_dependencies', true)) {
                    if ($this->hasCircularDependency($table, $referencedTable)) {
                        $errors[] = [
                            "message" => "Circular dependency detected: '{$table}' -> '{$referencedTable}' -> ... -> '{$table}'",
                            "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $refTable_match[1]), "\n"),
                            "type" => "circular_dependency",
                        ];
                    }
                    $this->recordDependency($table, $referencedTable);
                }
            }
        }

        return $errors;
    }

    /**
     * Validate index constraints
     */
    protected function validateIndexConstraintsInBlock(string $content, string $table, array $newColumns, int $blockStartLine, string $varName): array
    {
        $errors = [];
        
        // Get explicit constraints like $table->index(['col1', 'col2'])
        $constraints = $this->constraintParser->parseIndexConstraints($content);
        foreach ($constraints as $constraint) {
            $type = $constraint['type'];
            if ($type === 'index' || $type === 'fullText' || $type === 'spatialIndex') {
                foreach ($constraint['columns'] as $column) {
                    if ($this->tableExists($table) 
                        && !$this->columnExists($table, $column)
                        && !in_array($column, $newColumns)
                    ) {
                        $errors[] = [
                            "message" => "Column '{$column}' does not exist on table '{$table}' (used in {$type}())",
                            "lineNumber" => $blockStartLine + $constraint['lineNumber'] - 1,
                            "type" => "index_constraint",
                        ];
                    }
                }

                // Check for duplicate index
                if (config('preflight.checks.duplicate_indexes', true)) {
                    if ($this->hasDuplicateIndex($table, $type, $constraint['columns'])) {
                        $errors[] = [
                            "message" => "Duplicate {$type} detected on table '{$table}' for columns: " . implode(', ', $constraint['columns']),
                            "lineNumber" => $blockStartLine + $constraint['lineNumber'] - 1,
                            "type" => "duplicate_index",
                        ];
                    }
                    $this->recordIndex($table, $type, $constraint['columns']);
                }
            }
        }

        // Also detect chained ->index() calls on column definitions
        preg_match_all('/\$' . $varName . '\s*->\s*([a-zA-Z0-9_]+)\s*\(\s*["\'](\w+)["\'].*?\)->\s*(index|fullText|spatialIndex)\s*\(\s*\)/', $content, $chainedMatches, PREG_OFFSET_CAPTURE);
        
        foreach ($chainedMatches[2] as $idx => $column_match) {
            $column = $column_match[0];
            $constraintType = $chainedMatches[3][$idx][0];
            
            if ($this->tableExists($table) 
                && !$this->columnExists($table, $column)
                && !in_array($column, $newColumns)
            ) {
                $errors[] = [
                    "message" => "Column '{$column}' does not exist on table '{$table}' (used in {$constraintType}())",
                    "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                    "type" => "index_constraint",
                ];
            }

            // Check for duplicate index
            if (config('preflight.checks.duplicate_indexes', true)) {
                if ($this->hasDuplicateIndex($table, $constraintType, [$column])) {
                    $errors[] = [
                        "message" => "Duplicate {$constraintType} detected on table '{$table}' for column: {$column}",
                        "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                        "type" => "duplicate_index",
                    ];
                }
                $this->recordIndex($table, $constraintType, [$column]);
            }
        }

        return $errors;
    }

    /**
     * Validate unique constraints
     */
    protected function validateUniqueConstraintsInBlock(string $content, string $table, array $newColumns, int $blockStartLine, string $varName): array
    {
        $errors = [];
        $constraints = $this->constraintParser->parseIndexConstraints($content);

        foreach ($constraints as $constraint) {
            if ($constraint['type'] === 'unique') {
                foreach ($constraint['columns'] as $column) {
                    if ($this->tableExists($table) 
                        && !$this->columnExists($table, $column)
                        && !in_array($column, $newColumns)
                    ) {
                        $errors[] = [
                            "message" => "Column '{$column}' does not exist on table '{$table}' (used in unique())",
                            "lineNumber" => $blockStartLine + $constraint['lineNumber'] - 1,
                            "type" => "unique_constraint",
                        ];
                    }
                }

                // Check for duplicate unique constraint
                if (config('preflight.checks.duplicate_indexes', true)) {
                    if ($this->hasDuplicateIndex($table, 'unique', $constraint['columns'])) {
                        $errors[] = [
                            "message" => "Duplicate unique constraint detected on table '{$table}' for columns: " . implode(', ', $constraint['columns']),
                            "lineNumber" => $blockStartLine + $constraint['lineNumber'] - 1,
                            "type" => "duplicate_index",
                        ];
                    }
                    $this->recordIndex($table, 'unique', $constraint['columns']);
                }
            }
        }

        // Also detect chained ->unique() calls on column definitions
        preg_match_all('/\$' . $varName . '\s*->\s*([a-zA-Z0-9_]+)\s*\(\s*["\'](\w+)["\'].*?\)->\s*unique\s*\(\s*\)/', $content, $chainedMatches, PREG_OFFSET_CAPTURE);
        
        foreach ($chainedMatches[2] as $idx => $column_match) {
            $column = $column_match[0];
            
            // Duplicate check
            if (config('preflight.checks.duplicate_indexes', true)) {
                if ($this->hasDuplicateIndex($table, 'unique', [$column])) {
                    $errors[] = [
                        "message" => "Duplicate unique constraint detected on table '{$table}' for column: {$column}",
                        "lineNumber" => $blockStartLine + substr_count(substr($content, 0, $column_match[1]), "\n"),
                        "type" => "duplicate_index",
                    ];
                }
                $this->recordIndex($table, 'unique', [$column]);
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

    /**
     * Record a dependency between two tables
     */
    protected function recordDependency(string $table, string $referencedTable): void
    {
        if ($table === $referencedTable) {
            return;
        }

        if (!isset($this->dependencies[$table])) {
            $this->dependencies[$table] = [];
        }

        if (!in_array($referencedTable, $this->dependencies[$table])) {
            $this->dependencies[$table][] = $referencedTable;
        }
    }

    /**
     * Check if adding a dependency creates a circular reference
     */
    protected function hasCircularDependency(string $table, string $referencedTable): bool
    {
        return $this->detectCycle($referencedTable, $table, []);
    }

    /**
     * Depth-first search to detect cycles in dependency graph
     */
    protected function detectCycle(string $current, string $target, array $visited): bool
    {
        if ($current === $target) {
            return true;
        }

        if (in_array($current, $visited)) {
            return false;
        }

        $visited[] = $current;

        if (isset($this->dependencies[$current])) {
            foreach ($this->dependencies[$current] as $neighbor) {
                if ($this->detectCycle($neighbor, $target, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Record an index for a table
     */
    protected function recordIndex(string $table, string $type, array $columns): void
    {
        if (!isset($this->indexes[$table])) {
            $this->indexes[$table] = [];
        }

        sort($columns);

        $this->indexes[$table][] = [
            'type' => $type,
            'columns' => $columns,
        ];
    }

    /**
     * Check if an index already exists for a table
     */
    protected function hasDuplicateIndex(string $table, string $type, array $columns): bool
    {
        if (!isset($this->indexes[$table])) {
            return false;
        }

        sort($columns);

        foreach ($this->indexes[$table] as $index) {
            if ($index['type'] === $type && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }
}
