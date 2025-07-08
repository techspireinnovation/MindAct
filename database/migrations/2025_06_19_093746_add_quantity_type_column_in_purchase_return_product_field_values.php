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
        Schema::table('purchase_return_product_field_values', function (Blueprint $table) {
              $table->string('quantity_type')->after('quantity_index')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_product_field_values', function (Blueprint $table) {
             $table->dropColumn('quantity_type');
        });
    }
};
