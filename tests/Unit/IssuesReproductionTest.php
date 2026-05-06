<?php

namespace MigrationPreflight\Tests\Unit;

use MigrationPreflight\Services\ConstraintParser;
use MigrationPreflight\Services\MigrationValidator;
use MigrationPreflight\Services\SchemaInspector;
use MigrationPreflight\Tests\TestCase;
use Mockery;

class IssuesReproductionTest extends TestCase
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

    /**
     * Issue 1: False positive on after() when column is created in same migration
     */
    public function test_it_does_not_error_when_after_references_column_created_in_same_migration(): void
    {
        $content = "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint \$table) {
            \$table->string('letssz_id', 20)->unique()->after('id')->nullable();            \$table->boolean('is_verified')->default(false)->after('status');
            \$table->decimal('latitude', 10, 8)->nullable()->after('interest');
            \$table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            \$table->timestamp('last_seen_at')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint \$table) {
            \$table->dropColumn(['letssz_id', 'is_verified', 'latitude', 'longitude', 'last_seen_at']);
        });
    }
};";

        $this->schema->shouldReceive('tableExists')->with('users')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'id')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'status')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('users', 'interest')->andReturn(true);
        
        // These are being created
        $this->schema->shouldReceive('columnExists')->with('users', 'letssz_id')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'is_verified')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'latitude')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'longitude')->andReturn(false);
        $this->schema->shouldReceive('columnExists')->with('users', 'last_seen_at')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $this->assertEmpty($errors, 'Should not error when after() references a column created in the same migration. Found: ' . json_encode($errors));
    }

    /**
     * Issue 2: Cannot detect table name variations
     */
    public function test_it_detects_table_name_with_various_whitespaces(): void
    {
        $variations = [
            "Schema::table('products', function (Blueprint \$table) {",
            "Schema::table(\"products\", function (Blueprint \$table) {",
            "Schema  ::  table  (  'products'  , function (Blueprint \$table) {",
            "Schema::create('products', function (Blueprint \$table) {",
            "\Illuminate\Support\Facades\Schema::table('products', function (Blueprint \$table) {",
            "Schema::table(
                'products',
                function (Blueprint \$table) {"
        ];

        foreach ($variations as $content) {
            $this->schema->shouldReceive('tableExists')->with('products')->andReturn(true);
            $errors = $this->validator->validateContent($content);
            
            $hasTableError = false;
            foreach ($errors as $error) {
                if ($error['message'] === "Cannot detect table name") {
                    $hasTableError = true;
                }
            }
            
            $this->assertFalse($hasTableError, "Failed to detect table name in: {$content}");
        }
    }

    /**
     * Issue 2: Specific failing case from issues.md
     */
    public function test_it_detects_table_name_in_complex_migration(): void
    {
        $content = "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint \$table) {
            \$table->dropColumn('sale_price');
            \$table->foreignId('category_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }
};";

        $this->schema->shouldReceive('tableExists')->with('products')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('products', 'sale_price')->andReturn(true);
        $this->schema->shouldReceive('columnExists')->with('products', 'id')->andReturn(true);
        $this->schema->shouldReceive('tableExists')->with('categories')->andReturn(false);

        $errors = $this->validator->validateContent($content);
        
        $hasForeignKeyError = false;
        foreach ($errors as $error) {
            if ($error['type'] === "foreign_key" && strpos($error['message'], "categories") !== false) {
                $hasForeignKeyError = true;
            }
        }
        
        $this->assertTrue($hasForeignKeyError, "Failed to detect missing referenced table 'categories'. Errors: " . json_encode($errors));
    }

    /**
     * Test line number reporting accuracy
     */
    public function test_it_reports_correct_line_numbers_relative_to_file(): void
    {
        $migration = "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint \$table) {
            \$table->foreignId('non_existent_table_id')->constrained();
        });
    }
};";

        $this->schema->shouldReceive('tableExists')->with('orders')->andReturn(true);
        $this->schema->shouldReceive('tableExists')->with('non_existent_tables')->andReturn(false);

        $errors = $this->validator->validateContent($migration);
        
        $this->assertCount(1, $errors);
        // The Schema::table line is line 14. 
        // The foreignId line is line 15.
        $this->assertEquals(15, $errors[0]['lineNumber'], 'Error should be reported on line 15. Found: ' . $errors[0]['lineNumber']);
    }

    /**
     * Test up() method extraction when it is the last method in the file
     */
    public function test_it_extracts_up_method_content_when_it_is_the_last_method(): void
    {
        $migration = "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('only_up', function (Blueprint \$table) {
            \$table->id();
        });
    }
};";

        $this->schema->shouldReceive('tableExists')->with('only_up')->andReturn(false);
        $errors = $this->validator->validateContent($migration);
        
        $this->assertTrue(in_array('only_up', $this->getPrivateProperty($this->validator, 'virtualTables')), 'Failed to detect table in up() method when it is the last method');
    }

    /**
     * Test block detection when closure uses 'use' keyword
     */
    public function test_it_detects_blocks_with_use_keyword_in_closure(): void
    {
        $migration = "<?php
Schema::create('with_use', function (Blueprint \$table) use (\$extra) {
    \$table->id();
});";

        $this->schema->shouldReceive('tableExists')->with('with_use')->andReturn(false);
        $errors = $this->validator->validateContent($migration);
        
        $this->assertTrue(in_array('with_use', $this->getPrivateProperty($this->validator, 'virtualTables')), 'Failed to detect table created in closure with "use" keyword');
    }

    /**
     * Test cross-migration column dependency detection
     */
    public function test_it_detects_columns_created_in_previous_pending_migrations(): void
    {
        $migration1 = "<?php
Schema::create('pending_table', function (Blueprint \$table) {
    \$table->id();
    \$table->string('status');
});";

        $migration2 = "<?php
Schema::table('pending_table', function (Blueprint \$table) {
    \$table->string('new_col')->after('status');
});";

        $this->schema->shouldReceive('tableExists')->with('pending_table')->andReturn(false);
        
        // Validate M1 first
        $errors1 = $this->validator->validateContent($migration1);
        $this->assertEmpty($errors1);
        
        // M2 should now see 'status' in virtualColumns
        $errors2 = $this->validator->validateContent($migration2);
        $this->assertEmpty($errors2, 'Should have found column "status" in virtual columns. Errors: ' . json_encode($errors2));
    }

    protected function getPrivateProperty($object, $propertyName)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}
