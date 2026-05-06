##TEST

php artisan migrate:preflight --verbose
﻿
Running migration preflight...
Verbose mode enabled

Checking: 2026_05_04_131723_create_pickup_locations_table
Checking: 2026_05_04_132012_add_pickup_location_id_to_orders_table

Checked: 2 migrations

Preflight FAILED:

2026_05_04_132012_add_pickup_location_id_to_orders_table
 - [Line 3] Missing referenced table 'pickup_locations' for 'pickup_location_id'
   ─────────────────────────────
     1: <?php
     2:
   > 3: use Illuminate\Database\Migrations\Migration;
     4: use Illuminate\Database\Schema\Blueprint;
     5: use Illuminate\Support\Facades\Schema;
     6:
   ─────────────────────────────


Total errors found: 1


##Table

<?php

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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pickup_location_id')->nullable()->after('address_id')->constrained('pickup_locations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pickup_location_id']);
            $table->dropColumn('pickup_location_id');
        });
    }
};

there is PickupLocation Model and  table but it is not being detected by the preflight check. The error message indicates that the 'pickup_locations' table is missing, which suggests that the preflight check may not be correctly identifying existing tables or there may be an issue with the migration order.
