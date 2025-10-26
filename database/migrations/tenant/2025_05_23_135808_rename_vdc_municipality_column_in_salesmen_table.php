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
        // Check if the salesmen table exists
        if (Schema::connection('tenant')->hasTable('salesmen')) {
            // Check if the vdc/municipality column exists and vdc_municipality does not
            if (Schema::connection('tenant')->hasColumn('salesmen', 'vdc/municipality') && !Schema::hasColumn('salesmen', 'vdc_municipality')) {
                Schema::connection('tenant')->table('salesmen', function (Blueprint $table) {
                    $table->renameColumn('vdc/municipality', 'vdc_municipality');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    if (Schema::connection('tenant')->hasTable('salesmen')) {
        if (Schema::connection('tenant')->hasColumn('salesmen', 'vdc_municipality')) {
            Schema::connection('tenant')->table('salesmen', function (Blueprint $table) {
                $table->renameColumn('vdc_municipality', 'vdc/municipality');
            });
        }
    }
}

};