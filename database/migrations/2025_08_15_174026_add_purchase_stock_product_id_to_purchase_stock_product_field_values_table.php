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
        Schema::table('purchase_stock_product_field_values', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable()->after('stock_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_stock_product_field_values', function (Blueprint $table) {
             $table->dropColumn('purchase_stock_product_id');
        });
    }
};
