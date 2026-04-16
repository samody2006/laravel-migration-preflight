<?php

namespace MigrationPreflight\Commands;

use Illuminate\Console\Command;
use MigrationPreflight\Services\MigrationScanner;
use MigrationPreflight\Services\MigrationValidator;

class PreflightCommand extends Command
{
    protected $signature = 'migrate:preflight {--verbose : Show detailed error information with line numbers}';
    protected $description = 'Run migration safety preflight checks';

    public function handle(
        MigrationScanner $scanner,
        MigrationValidator $validator
    ): int {
        $verbose = $this->option('verbose');

        $this->info("Running migration preflight...");
        if ($verbose) {
            $this->line("Verbose mode enabled\n");
        }

        $migrations = $scanner->getPendingMigrations();

        if (empty($migrations)) {
            $this->info("No pending migrations.");
            return 0;
        }

        $errors = [];
        $checked = 0;

        foreach ($migrations as $migration) {
            $this->line("Checking: {$migration}");
            $checked++;

            $result = $validator->validate($migration);

            if (!empty($result)) {
                $errors[$migration] = $result;
            }
        }

        // Summary
        $this->line("");
        $this->info("Checked: {$checked} migrations");

        if (!empty($errors)) {
            $this->error("\nPreflight FAILED:");
            $this->displayErrors($errors, $verbose);
            return 1;
        }

        $this->info("✓ All migrations passed preflight checks.");
        return 0;
    }

    /**
     * Display errors with optional verbose output
     */
    protected function displayErrors(array $errors, bool $verbose): void
    {
        $errorCount = 0;

        foreach ($errors as $file => $messages) {
            $this->warn("\n{$file}");
            
            foreach ($messages as $msg) {
                $errorCount++;
                
                if (is_array($msg)) {
                    $lineNumber = $msg['lineNumber'] ?? 'unknown';
                    $message = $msg['message'] ?? '';
                    $type = $msg['type'] ?? 'error';
                    
                    $this->error(" - [Line {$lineNumber}] {$message}");
                    
                    if ($verbose) {
                        $this->displayCodeContext($file, $lineNumber);
                    }
                } else {
                    // Fallback for string errors
                    $this->error(" - {$msg}");
                }
            }
        }

        $this->error("\nTotal errors found: {$errorCount}");
    }

    /**
     * Display code context around the error line
     */
    protected function displayCodeContext(string $migration, int $lineNumber): void
    {
        $path = database_path("migrations/{$migration}.php");
        
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $startLine = max(0, $lineNumber - 3);
        $endLine = min(count($lines) - 1, $lineNumber + 2);

        $this->line("   <fg=gray>─────────────────────────────</>");
        
        for ($i = $startLine; $i <= $endLine; $i++) {
            $currentLineNum = $i + 1;
            $marker = $currentLineNum === $lineNumber ? ">" : " ";
            $lineContent = $lines[$i] ?? '';
            
            if ($currentLineNum === $lineNumber) {
                $this->line("   <fg=red>{$marker} {$currentLineNum}: {$lineContent}</>");
            } else {
                $this->line("   <fg=gray>{$marker} {$currentLineNum}: {$lineContent}</>");
            }
        }
        
        $this->line("   <fg=gray>─────────────────────────────</>\n");
    }
}