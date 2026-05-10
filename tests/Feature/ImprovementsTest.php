<?php

namespace MigrationPreflight\Tests\Feature;

use MigrationPreflight\Services\MigrationScanner;
use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;
use Illuminate\Support\Facades\Config;

class ImprovementsTest extends TestCase
{
    public function test_ignores_specified_tables(): void
    {
        $content = "
Schema::table('ignored_table', function (Blueprint \$table) {
    \$table->string('name');
});
";
        Config::set('preflight.ignore.tables', ['ignored_table']);

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('ignored_table')->andReturn(false);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $this->assertCount(0, $errors);
    }

    public function test_detects_circular_dependencies(): void
    {
        $migration1 = "
Schema::create('table_a', function (Blueprint \$table) {
    \$table->id();
    \$table->foreignId('b_id')->constrained('table_b');
});
";
        $migration2 = "
Schema::create('table_b', function (Blueprint \$table) {
    \$table->id();
    \$table->foreignId('a_id')->constrained('table_a');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->andReturn(true);

        $validator = new MigrationValidator($schema);
        
        // Validate first migration - should be fine
        $errors1 = $validator->validateContent($migration1);
        $this->assertCount(0, $errors1);

        // Validate second migration - should detect cycle
        $errors2 = $validator->validateContent($migration2);
        
        $circularErrors = array_values(array_filter($errors2, fn($e) => $e['type'] === 'circular_dependency'));
        $this->assertCount(1, $circularErrors);
        $this->assertStringContainsString('Circular dependency detected', $circularErrors[0]['message']);
    }

    public function test_detects_duplicate_indexes(): void
    {
        $content = "
Schema::create('users', function (Blueprint \$table) {
    \$table->id();
    \$table->string('email');
    \$table->index('email');
    \$table->index('email'); // Duplicate
    \$table->unique('email'); // Not a duplicate (different type)
    \$table->unique('email'); // Duplicate
});
";
        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->andReturn(true);
        $schema->shouldReceive('columnExists')->andReturn(true);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $duplicateErrors = array_filter($errors, fn($e) => $e['type'] === 'duplicate_index');
        $this->assertCount(2, $duplicateErrors);
    }

    public function test_detects_duplicate_chained_indexes(): void
    {
        $content = "
Schema::table('users', function (Blueprint \$table) {
    \$table->string('email')->index()->unique();
    \$table->string('other')->index();
    \$table->index('other'); // Duplicate
});
";
        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->andReturn(true);
        $schema->shouldReceive('columnExists')->andReturn(true);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $duplicateErrors = array_filter($errors, fn($e) => $e['type'] === 'duplicate_index');
        $this->assertCount(1, $duplicateErrors);
    }

    public function test_detects_migration_order_issues(): void
    {
        // Table B depends on Table A
        $migrationB = "
Schema::create('table_b', function (Blueprint \$table) {
    \$table->id();
    \$table->foreignId('a_id')->constrained('table_a');
});
";
        // Migration A creates Table A (but it will be 'processed' later in this test)
        $migrationA = "
Schema::create('table_a', function (Blueprint \$table) {
    \$table->id();
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->andReturn(false);

        $validator = new MigrationValidator($schema);
        
        // If we validate B first, it should fail because A doesn't exist yet
        $errorsB = $validator->validateContent($migrationB);
        $this->assertCount(1, $errorsB);
        $this->assertStringContainsString('table_a', $errorsB[0]['message']);

        // Now if we validate A, then B, it should pass
        $validator->clearVirtualState();
        $validator->validateContent($migrationA);
        $errorsB_ok = $validator->validateContent($migrationB);
        $this->assertCount(0, $errorsB_ok);
    }
}
