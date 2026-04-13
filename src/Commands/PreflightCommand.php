<?php

namespace MigrationPreflight\Commands;

use Illuminate\Console\Command;
use MigrationPreflight\Services\MigrationScanner;
use MigrationPreflight\Services\ForeignKeyValidator;

class PreflightCommand extends Command
{
    protected $signature = 'migrate:preflight';
    protected $description = 'Run migration safety preflight checks';

    public function handle(
        MigrationScanner $scanner,
        ForeignKeyValidator $validator
    ): int {
        $this->info("Running migration preflight...");

        $migrations = $scanner->getPendingMigrations();

        if (empty($migrations)) {
            $this->info("No pending migrations.");
            return 0;
        }

        $errors = [];

        foreach ($migrations as $migration) {
            $this->line("Checking: {$migration}");

            $result = $validator->validate($migration);

            if (!empty($result)) {
                $errors[$migration] = $result;
            }
        }

        if (!empty($errors)) {
            $this->error("\nPreflight FAILED:");

            foreach ($errors as $file => $msgs) {
                $this->warn("\n{$file}");
                foreach ($msgs as $msg) {
                    $this->error(" - {$msg}");
                }
            }

            return 1;
        }

        $this->info("All migrations passed preflight checks.");
        return 0;
    }
}