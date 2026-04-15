<?php

namespace MigrationPreflight\Tests\Unit;

use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;

class MigrationValidatorTest extends TestCase
{
    protected MigrationValidator $validator;
    protected $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = Mockery::mock(SchemaInspector::class);
        $this->validator = new MigrationValidator($this->schema);
    }

    public function test_it_detects_missing_table_in_schema_table(): void
    {
        $content = "Schema::table('users', function (\$table) { \$table->string('email'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        $this->assertContains("Table 'users' does not exist", $errors);
    }

    public function test_it_does_not_error_if_table_exists_in_schema_table(): void
    {
        $content = "Schema::table('users', function (\$table) { \$table->string('email'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);

        $errors = $this->validator->validateContent($content);
        $this->assertEmpty($errors);
    }

    public function test_it_detects_missing_column_in_after(): void
    {
        $content = "Schema::table('users', function (\$table) { \$table->string('email')->after('name'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'name')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        $this->assertContains("Column 'name' does not exist on table 'users' (used in after())", $errors);
    }

    public function test_it_guesses_plural_table_name_for_foreign_id(): void
    {
        $content = "Schema::create('orders', function (\$table) { \$table->foreignId('user_id')->constrained(); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        $this->assertContains("Missing referenced table 'users' for 'user_id'", $errors);
    }

    public function test_it_uses_explicit_table_name_in_constrained(): void
    {
        $content = "Schema::create('orders', function (\$table) { \$table->foreignId('owner_id')->constrained('users'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        $this->assertContains("Missing referenced table 'users' for 'owner_id'", $errors);
    }

    public function test_it_handles_plural_edge_cases_correctly(): void
    {
        $content = "Schema::create('items', function (\$table) { \$table->foreignId('category_id')->constrained(); });";
        $this->schema->shouldReceive('tableExists')->with('categories')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        $this->assertContains("Missing referenced table 'categories' for 'category_id'", $errors);
    }
}