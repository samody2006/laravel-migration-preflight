<?php

namespace MigrationPreflight\Tests\Feature;

use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;

class CrossMigrationValidationTest extends TestCase
{
    public function test_it_should_detect_tables_created_in_previous_migrations_in_same_run(): void
    {
        $migration1 = "
Schema::create('pickup_locations', function (Blueprint \$table) {
    \$table->id();
    \$table->string('name');
});
";
        $migration2 = "
Schema::table('orders', function (Blueprint \$table) {
    \$table->foreignId('pickup_location_id')->constrained('pickup_locations');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        
        // Initial state: neither exists in DB
        $schema->shouldReceive('tableExists')->with('pickup_locations')->andReturn(false);
        $schema->shouldReceive('tableExists')->with('orders')->andReturn(true);
        $schema->shouldReceive('columnExists')->with('orders', 'pickup_location_id')->andReturn(false);

        $validator = new MigrationValidator($schema);
        
        // Validate first migration - should pass and we should somehow remember 'pickup_locations' was created
        $errors1 = $validator->validateContent($migration1);
        $this->assertEmpty($errors1);

        // Validate second migration - this currently fails because 'pickup_locations' is not in DB
        $errors2 = $validator->validateContent($migration2);
        
        $this->assertEmpty($errors2, "Should not error when referencing a table created in a previous migration in the same run. Errors: " . json_encode($errors2));
    }
}
