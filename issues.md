PS C:\laragon\www\letzss> php artisan migrate:preflight --verbose                                                          
Running migration preflight...
Verbose mode enabled

Checking: 2026_04_30_145828_add_profile_fields_to_users_table
Checking: 2026_04_30_145828_create_moments_table
Checking: 2026_04_30_145828_create_profiles_table

Checked: 3 migrations

Preflight FAILED:

2026_04_30_145828_add_profile_fields_to_users_table
 - [Line 18] Column 'latitude' does not exist on table 'users' (used in after())
   ─────────────────────────────
     16:             $table->boolean('is_verified')->default(false)->after('status');
     17:             $table->decimal('latitude', 10, 8)->nullable()->after('interest');
   > 18:             $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
     19:             $table->timestamp('last_seen_at')->nullable()->after('longitude');
     20:         });
     21:     }
   ─────────────────────────────

 - [Line 19] Column 'longitude' does not exist on table 'users' (used in after())
   ─────────────────────────────
     17:             $table->decimal('latitude', 10, 8)->nullable()->after('interest');
     18:             $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
   > 19:             $table->timestamp('last_seen_at')->nullable()->after('longitude');
     20:         });
     21:     }
     22: 
   ─────────────────────────────

 - [Line 18] Column 'latitude' does not exist on table 'users' (used in after())
   ─────────────────────────────
     16:             $table->boolean('is_verified')->default(false)->after('status');
     17:             $table->decimal('latitude', 10, 8)->nullable()->after('interest');
   > 18:             $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
     19:             $table->timestamp('last_seen_at')->nullable()->after('longitude');
     20:         });
     21:     }
   ─────────────────────────────

 - [Line 19] Column 'longitude' does not exist on table 'users' (used in after())
   ─────────────────────────────
     17:             $table->decimal('latitude', 10, 8)->nullable()->after('interest');
     18:             $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
   > 19:             $table->timestamp('last_seen_at')->nullable()->after('longitude');
     20:         });
     21:     }
     22: 
   ─────────────────────────────


Total errors found: 4


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
        Schema::table('users', function (Blueprint $table) {
            $table->string('letssz_id', 20)->unique()->after('id')->nullable();            $table->boolean('is_verified')->default(false)->after('status');
            $table->decimal('latitude', 10, 8)->nullable()->after('interest');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->timestamp('last_seen_at')->nullable()->after('longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['letssz_id', 'is_verified', 'latitude', 'longitude', 'last_seen_at']);
        });
    }
};

PS C:\laragon\www\ATG-Backend> php artisan migrate:preflight --verbose
﻿
Running migration preflight...
Checking: 2026_03_30_111130_create_categories_table
Checking: 2026_04_27_152317_create_notifications_table
Checking: 2026_05_01_131351_modify_products_table_remove_sale_price_add_category_id

Preflight FAILED:

2026_05_01_131351_modify_products_table_remove_sale_price_add_category_id
 - Cannot detect table name

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
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sale_price');
            $table->foreignId('category_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('sale_price')->nullable()->after('price');
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
but there is product model already that has it table
and the migration didn't failed when I tried it

