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
       Schema::connection('tenant')->table('production_assembles', function (Blueprint $table) {
            // Remove old column
            $table->dropColumn('production_date');

            // Add new columns
            $table->string('production_date_bs')->nullable()->after('product_quantity'); // for BS date (string)
            $table->date('production_date_ad')->nullable()->after('production_date_bs'); // for AD date
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->table('production_assembles', function (Blueprint $table) {
            // Remove new columns
            $table->dropColumn(['production_date_bs', 'production_date_ad']);

            // Restore old column
            $table->date('production_date')->nullable()->after('product_quantity');
        });
    }
};
