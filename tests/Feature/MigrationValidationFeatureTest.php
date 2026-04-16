<?php

namespace MigrationPreflight\Tests\Feature;

use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;

class MigrationValidationFeatureTest extends TestCase
{
    public function test_validates_complex_migration_with_multiple_issues(): void
    {
        $content = "
Schema::create('orders', function (Blueprint \$table) {
    \$table->id();
    \$table->string('order_number')->unique();
    \$table->foreignId('user_id')->constrained();
    \$table->foreignId('company_id')->constrained('companies');
    \$table->decimal('total', 10, 2);
    \$table->timestamps();
    \$table->index(['user_id', 'created_at']);
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('users')->andReturn(false);
        $schema->shouldReceive('tableExists')->with('companies')->andReturn(false);
        $schema->shouldReceive('tableExists')->with('orders')->andReturn(true);
        $schema->shouldReceive('columnExists')->with('orders', \Mockery::any())->andReturn(true);
        
        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        // Should find 2 foreign key errors
        $foreignKeyErrors = array_filter($errors, fn($e) => $e['type'] === 'foreign_key');
        $this->assertCount(2, $foreignKeyErrors);
    }

    public function test_validates_migration_with_alterations_on_non_existent_table(): void
    {
        $content = "
Schema::table('products', function (Blueprint \$table) {
    \$table->string('sku')->after('name')->unique();
    \$table->dropColumn('old_column');
    \$table->renameColumn('price', 'sale_price');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('products')->andReturn(false);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        // Should find the missing table error
        $tableErrors = array_filter($errors, fn($e) => $e['type'] === 'missing_table');
        $this->assertCount(1, $tableErrors);
    }

    public function test_validates_indexes_with_missing_columns(): void
    {
        $content = "
Schema::create('users', function (Blueprint \$table) {
    \$table->id();
    \$table->string('email');
    \$table->index('email');
    \$table->fullText('bio', 'description');
    \$table->unique(['email', 'username']);
    \$table->spatialIndex('location');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $schema->shouldReceive('columnExists')->with('users', 'email')->andReturn(true);
        $schema->shouldReceive('columnExists')->with('users', 'bio')->andReturn(false);
        $schema->shouldReceive('columnExists')->with('users', 'description')->andReturn(false);
        $schema->shouldReceive('columnExists')->with('users', 'username')->andReturn(false);
        $schema->shouldReceive('columnExists')->with('users', 'location')->andReturn(false);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $indexErrors = array_filter($errors, fn($e) => $e['type'] === 'index_constraint');
        $uniqueErrors = array_filter($errors, fn($e) => $e['type'] === 'unique_constraint');

        $this->assertGreaterThan(0, count($indexErrors));
        $this->assertGreaterThan(0, count($uniqueErrors));
    }

    public function test_all_errors_have_required_fields(): void
    {
        $content = "
Schema::table('users', function (Blueprint \$table) {
    \$table->string('email')->after('missing_column');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $schema->shouldReceive('columnExists')->with('users', 'missing_column')->andReturn(false);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $this->assertNotEmpty($errors);
        
        foreach ($errors as $error) {
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('lineNumber', $error);
            $this->assertArrayHasKey('type', $error);
            $this->assertIsString($error['message']);
            $this->assertIsInt($error['lineNumber']);
            $this->assertIsString($error['type']);
        }
    }

    public function test_foreign_key_with_references_and_on(): void
    {
        $content = "
Schema::create('orders', function (Blueprint \$table) {
    \$table->id();
    \$table->unsignedBigInteger('customer_id');
    \$table->foreign('customer_id')->references('id')->on('customers');
});
";

        $schema = Mockery::mock(SchemaInspector::class);
        $schema->shouldReceive('tableExists')->with('customers')->andReturn(false);

        $validator = new MigrationValidator($schema);
        $errors = $validator->validateContent($content);

        $foreignKeyErrors = array_filter($errors, fn($e) => $e['type'] === 'foreign_key');
        $this->assertCount(1, $foreignKeyErrors);
        $this->assertStringContainsString('customers', $foreignKeyErrors[0]['message']);
    }
}

