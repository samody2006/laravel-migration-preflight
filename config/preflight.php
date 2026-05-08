<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the preflight process will stop immediately if any
    | migration issue is detected.
    |
    | Disable this if you prefer warnings instead of blocking execution.
    |
    */

    'strict' => true,

    /*
    |--------------------------------------------------------------------------
    | Validation Checks
    |--------------------------------------------------------------------------
    |
    | These checks are executed before pending migrations run.
    | Disable individual checks if they are not relevant to your project.
    |
    */

    'checks' => [

        /*
        |--------------------------------------------------------------------------
        | Missing Tables
        |--------------------------------------------------------------------------
        |
        | Detect references to database tables that do not exist or are not
        | scheduled to be created before dependent migrations execute.
        |
        */

        'missing_tables' => true,

        /*
        |--------------------------------------------------------------------------
        | Missing Columns
        |--------------------------------------------------------------------------
        |
        | Validate referenced columns exist before relationships, indexes,
        | or constraints are applied.
        |
        */

        'missing_columns' => true,

        /*
        |--------------------------------------------------------------------------
        | Foreign Key Validation
        |--------------------------------------------------------------------------
        |
        | Ensure foreign key relationships reference valid tables and columns.
        |
        | This helps prevent SQL errors caused by invalid references or
        | migration ordering issues.
        |
        */

        'foreign_keys' => true,

        /*
        |--------------------------------------------------------------------------
        | Index Constraint Validation
        |--------------------------------------------------------------------------
        |
        | Validate indexes are applied only to existing columns and compatible
        | database structures.
        |
        */

        'index_constraints' => true,

        /*
        |--------------------------------------------------------------------------
        | Unique Constraint Validation
        |--------------------------------------------------------------------------
        |
        | Ensure unique constraints target valid and existing columns.
        |
        */

        'unique_constraints' => true,
    ],

];