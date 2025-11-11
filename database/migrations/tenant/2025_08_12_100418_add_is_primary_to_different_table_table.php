<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->table('cashes', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('is_active');
        });

       Schema::connection('tenant')->table('work_shifts', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('is_active');
        });
       Schema::connection('tenant')->table('nozzles', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::table('cashes', function (Blueprint $table) {
        //     $table->dropColumn('is_primary');

        // });

        // Schema::table('work_shifts', function (Blueprint $table) {
        //     $table->dropColumn('is_primary');

        // });
        // Schema::table('nozzles', function (Blueprint $table) {
        //     $table->dropColumn('is_primary');

        // });
        foreach (['cashes', 'work_shifts', 'nozzles'] as $tableName) {
            Schema::connection('tenant')->table($tableName, function (Blueprint $table) {
                if (Schema::connection('tenant')->hasColumn($table->getTable(), 'is_primary')) {
                    $table->dropColumn('is_primary');
                }
            });
        }
    }
};
