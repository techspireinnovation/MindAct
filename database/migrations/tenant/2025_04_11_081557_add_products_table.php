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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('product_unique_id')->nullable();
            $table->text('debit_note')->nullable();
            $table->text('credit_note')->nullable();
            $table->foreignId('company_id');
            $table->foreignId('category_id')->nullable();
            $table->foreignId('sub_category_id')->nullable();
            $table->foreignId('brand_id')->nullable();
            $table->foreignId('measure_unit_id')->nullable();
            $table->double('purchase_rate')->nullable();
            $table->double('purchase_rate_vat')->nullable();
            $table->double('retail_sales_price')->nullable();
            $table->double('retail_sales_price_vat')->nullable();
            $table->double('retail_sales_price_profit_percent')->nullable();
            $table->double('wholesales_price')->nullable();
            $table->double('wholesales_price_vat')->nullable();
            $table->double('wholesales_price_profit_percent')->nullable();
            $table->boolean('is_vatable')->default(0);
            $table->foreignId('product_type_id')->nullable();
            $table->foreignId('location_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->text('stock_alert')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
