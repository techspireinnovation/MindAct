<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_product_returns', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();
            $table->string('free_quantity')->nullable()->change();
        });

        Schema::connection('tenant')->table('purchase_stock_products', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();
            $table->string('free_quantity')->nullable()->change();
        });

        Schema::table('sale_products', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();
            $table->string('free_quantity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_product_returns', function (Blueprint $table) {
            $table->double('quantity')->nullable()->change();
            $table->double('free_quantity')->nullable()->change();
        });

        Schema::table('purchase_stock_products', function (Blueprint $table) {
            $table->double('quantity')->nullable()->change();
            $table->double('free_quantity')->nullable()->change();
        });

        Schema::table('sale_products', function (Blueprint $table) {
            $table->double('quantity')->nullable()->change();
            $table->double('free_quantity')->nullable()->change();
        });
    }
};
