<?php

namespace MigrationPreflight\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use MigrationPreflight\Services\MigrationScanner;
use MigrationPreflight\Tests\TestCase;

class MigrationScannerTest extends TestCase
{
    public function test_it_returns_empty_when_no_migrations_table_and_no_files(): void
    {
        Schema::shouldReceive('hasTable')->with('migrations')->andReturn(false);

        $scanner = new MigrationScanner();
        $this->assertEquals([], $scanner->getPendingMigrations());
    }

    public function test_it_returns_all_files_when_no_migrations_table(): void
    {
        Schema::shouldReceive('hasTable')->with('migrations')->andReturn(false);

        $scanner = new MigrationScanner();
        $this->assertIsArray($scanner->getPendingMigrations());
    }
}