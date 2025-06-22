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
        Schema::table('sale_return_product_field_values', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_product_id')->constrained('sale_products')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_product_field_values', function (Blueprint $table) {
            $table->dropColumn('sale_product_id');
        });
    }
};
