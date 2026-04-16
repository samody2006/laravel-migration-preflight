<?php

namespace MigrationPreflight\Services;

class ConstraintParser
{
    /**
     * Extract index method calls and their columns
     * Matches: ->index(), ->index('name'), ->unique(), ->unique('name'), ->fullText(), ->spatialIndex()
     *
     * @param string $content
     * @return array Array of [type => string, columns => array, lineNumber => int]
     */
    public function parseIndexConstraints(string $content): array
    {
        $constraints = [];
        $lines = explode("\n", $content);

        // Match index constraint methods - improved to handle multi-line chains
        $pattern = '/->(index|unique|fullText|spatialIndex)\s*\(([^)]*)\)/i';

        foreach ($lines as $lineNumber => $line) {
            if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $idx => $match) {
                    $type = $matches[1][$idx][0];
                    $args = $matches[2][$idx][0];

                    $columns = $this->extractColumnsFromArgs($args);

                    // Only add if we found columns in the args
                    if (!empty($columns)) {
                        $constraints[] = [
                            'type' => $type,
                            'columns' => $columns,
                            'lineNumber' => $lineNumber + 1,
                            'method' => $match[0],
                        ];
                    }
                }
            }
        }

        return $constraints;
    }

    /**
     * Extract createIndex method calls
     * Matches: Schema::create|table|raw('CREATE INDEX ...'), DB::statement()
     *
     * @param string $content
     * @return array Array of [columns => array, lineNumber => int]
     */
    public function parseCreateIndexCalls(string $content): array
    {
        $indexes = [];
        $lines = explode("\n", $content);

        // Match DB::statement or Schema::raw calls that might create indexes
        $pattern = '/(?:DB::statement|Schema::raw)\s*\(\s*["\'](?:CREATE\s+(?:UNIQUE\s+)?INDEX|ALTER\s+TABLE[^"\']*KEY)\s+[^"\']*(?:ON|KEY)\s+([`"\']?)(\w+)\1\s*\([^)]*\)/i';

        foreach ($lines as $lineNumber => $line) {
            if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $idx => $match) {
                    $indexes[] = [
                        'raw' => $match[0],
                        'lineNumber' => $lineNumber + 1,
                    ];
                }
            }
        }

        return $indexes;
    }

    /**
     * Extract columns from constraint method arguments
     * Handles: 'column', ['column1', 'column2'], single string, etc.
     *
     * @param string $args
     * @return array
     */
    protected function extractColumnsFromArgs(string $args): array
    {
        $args = trim($args);

        if (empty($args)) {
            // For method calls without arguments like ->unique() on a column definition
            return [];
        }

        // Handle array syntax: ['col1', 'col2', 'col3']
        if (preg_match('/\[([^\]]+)\]/', $args, $matches)) {
            $columnStr = $matches[1];
            // Extract quoted strings
            preg_match_all('/["\']([^"\']+)["\']/', $columnStr, $cols);
            return $cols[1] ?? [];
        }

        // Handle single quoted string or unquoted identifier
        preg_match_all('/["\']([^"\']+)["\']/', $args, $matches);

        if (!empty($matches[1])) {
            return $matches[1];
        }

        return [];
    }

    /**
     * Parse column modification constraints (nullable, default, etc.)
     *
     * @param string $content
     * @return array
     */
    public function parseColumnConstraints(string $content): array
    {
        $constraints = [];
        $lines = explode("\n", $content);

        // Match method chains like: $table->string('email')->change()
        $pattern = '/\$table->(\w+)\s*\(\s*["\'](\w+)["\']\s*\)(?:.*?)->(change|nullable|default|unsigned|collation)\s*\(/';

        foreach ($lines as $lineNumber => $line) {
            if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $idx => $match) {
                    $columnMethod = $matches[1][$idx][0];
                    $column = $matches[2][$idx][0];
                    $constraintMethod = $matches[3][$idx][0];

                    if (in_array($constraintMethod, ['change', 'nullable', 'default', 'unsigned', 'collation'])) {
                        $constraints[] = [
                            'method' => $constraintMethod,
                            'column' => $column,
                            'lineNumber' => $lineNumber + 1,
                        ];
                    }
                }
            }
        }

        return $constraints;
    }
}

