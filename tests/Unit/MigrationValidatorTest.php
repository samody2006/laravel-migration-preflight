<?php

namespace MigrationPreflight\Tests\Unit;

use MigrationPreflight\Services\ConstraintParser;
use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;

class MigrationValidatorTest extends TestCase
{
    protected MigrationValidator $validator;
    protected $schema;
    protected ConstraintParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = Mockery::mock(SchemaInspector::class);
        $this->parser = new ConstraintParser();
        $this->validator = new MigrationValidator($this->schema, $this->parser);
    }

    public function test_it_detects_missing_table_in_schema_table(): void
    {
        $content = "Schema::table('users', function (\$table) { \$table->string('email'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertEquals("Table 'users' does not exist", $errors[0]['message']);
        $this->assertEquals('missing_table', $errors[0]['type']);
        $this->assertIsInt($errors[0]['lineNumber']);
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
        $content = "Schema::table('users', function (\$table) {
            \$table->string('email')->after('name');
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'name')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Column 'name' does not exist", $errors[0]['message']);
        $this->assertEquals('missing_column', $errors[0]['type']);
    }

    public function test_it_detects_missing_column_in_drop_column(): void
    {
        $content = "Schema::table('users', function (\$table) {
            \$table->dropColumn('phone');
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'phone')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Column 'phone' does not exist", $errors[0]['message']);
        $this->assertStringContainsString("dropColumn", $errors[0]['message']);
    }

    public function test_it_detects_missing_column_in_rename_column(): void
    {
        $content = "Schema::table('users', function (\$table) {
            \$table->renameColumn('old_name', 'new_name');
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'old_name')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("Column 'old_name' does not exist", $errors[0]['message']);
        $this->assertStringContainsString("renameColumn", $errors[0]['message']);
    }


    public function test_it_guesses_plural_table_name_for_foreign_id(): void
    {
        $content = "Schema::create('orders', function (\$table) { \$table->foreignId('user_id')->constrained(); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertEquals("Missing referenced table 'users' for 'user_id'", $errors[0]['message']);
        $this->assertEquals('foreign_key', $errors[0]['type']);
    }

    public function test_it_uses_explicit_table_name_in_constrained(): void
    {
        $content = "Schema::create('orders', function (\$table) { \$table->foreignId('owner_id')->constrained('users'); });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertEquals("Missing referenced table 'users' for 'owner_id'", $errors[0]['message']);
    }

    public function test_it_handles_plural_edge_cases_correctly(): void
    {
        $content = "Schema::create('items', function (\$table) { \$table->foreignId('category_id')->constrained(); });";
        $this->schema->shouldReceive('tableExists')->with('categories')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertEquals("Missing referenced table 'categories' for 'category_id'", $errors[0]['message']);
    }

    public function test_it_handles_multiline_foreign_key_with_method_chains(): void
    {
        $content = "Schema::table('company_settings', function (\$table) {
            \$table->foreignId('client_screening_level')
                ->after('occupant_screening_level')
                ->default(1)
                ->constrained('tiers')
                ->cascadeOnDelete();
        });";
        $this->schema->shouldReceive('tableExists')->with('company_settings')->andReturn(true);
        $this->schema->shouldReceive('tableExists')->with('tiers')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('company_settings', 'occupant_screening_level')->andReturn(true);

        $errors = $this->validator->validateContent($content);

        $this->assertNotEmpty($errors);
        $this->assertEquals("Missing referenced table 'tiers' for 'client_screening_level'", $errors[0]['message']);
        $this->assertEquals('foreign_key', $errors[0]['type']);
    }

    public function test_it_detects_missing_column_in_index_constraint(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->index('email');
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'email')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $indexErrors = array_filter($errors, fn($e) => $e['type'] === 'index_constraint');
        $this->assertNotEmpty($indexErrors);
    }

    public function test_it_detects_missing_columns_in_unique_constraint(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->unique(['email', 'username']);
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'email')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'username')->andReturn(true);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $this->assertEquals("Column 'email' does not exist on table 'users' (used in unique())", $errors[0]['message']);
        $this->assertEquals('unique_constraint', $errors[0]['type']);
    }

    public function test_it_detects_fulltext_index_on_missing_column(): void
    {
        $content = "Schema::create('posts', function (\$table) {
            \$table->fullText(['title', 'description']);
        });";
        $this->schema->shouldReceive('tableExists')->with('posts')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('posts', 'title')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('posts', 'description')->andReturn(true);

        $errors = $this->validator->validateContent($content);
        
        $this->assertNotEmpty($errors);
        $fullTextErrors = array_filter($errors, fn($e) => strpos($e['message'], 'fullText') !== false);
        $this->assertNotEmpty($fullTextErrors);
    }

    public function test_it_returns_line_numbers_for_errors(): void
    {
        $content = "Schema::table('users', function (\$table) {
            \$table->string('email')->after('name');
            \$table->dropColumn('phone');
        });";
        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'name')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'phone')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertCount(2, $errors);
        $this->assertIsInt($errors[0]['lineNumber']);
        $this->assertIsInt($errors[1]['lineNumber']);
        // Both errors should have different line numbers
        $this->assertTrue($errors[0]['lineNumber'] !== $errors[1]['lineNumber']);
    }
}