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
            $table->double('product_defect_quantity')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('production_assembles', function (Blueprint $table) {
             $table->dropColumn('product_defect_quantity');
        });
    }
};
