<?php

namespace MigrationPreflight\Tests\Unit;

use MigrationPreflight\Services\ConstraintParser;
use MigrationPreflight\Tests\TestCase;

class ConstraintParserTest extends TestCase
{
    protected ConstraintParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ConstraintParser();
    }

    public function test_it_parses_explicit_index_constraints(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->index('email');
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(1, $constraints);
        $this->assertEquals('index', $constraints[0]['type']);
        $this->assertContains('email', $constraints[0]['columns']);
    }

    public function test_it_parses_explicit_unique_constraint_with_column(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->unique('email');
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(1, $constraints);
        $this->assertEquals('unique', $constraints[0]['type']);
    }

    public function test_it_parses_unique_constraint_with_multiple_columns(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->unique(['email', 'username']);
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(1, $constraints);
        $this->assertEquals('unique', $constraints[0]['type']);
        $this->assertContains('email', $constraints[0]['columns']);
        $this->assertContains('username', $constraints[0]['columns']);
    }

    public function test_it_parses_fulltext_constraint(): void
    {
        $content = "Schema::create('posts', function (\$table) {
            \$table->fullText(['title', 'description']);
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(1, $constraints);
        $this->assertEquals('fullText', $constraints[0]['type']);
    }

    public function test_it_parses_spatial_index_constraint(): void
    {
        $content = "Schema::create('locations', function (\$table) {
            \$table->spatialIndex(['coordinates']);
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(1, $constraints);
        $this->assertEquals('spatialIndex', $constraints[0]['type']);
    }

    public function test_it_extracts_correct_line_numbers(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->id();
            \$table->unique('email');
            \$table->index('username');
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(2, $constraints);
        $this->assertEquals(3, $constraints[0]['lineNumber']);
        $this->assertEquals(4, $constraints[1]['lineNumber']);
    }

    public function test_it_handles_multiple_constraints_per_line(): void
    {
        $content = "Schema::create('users', function (\$table) {
            \$table->index('email'); \$table->unique('username');
        });";

        $constraints = $this->parser->parseIndexConstraints($content);

        $this->assertCount(2, $constraints);
    }
}

