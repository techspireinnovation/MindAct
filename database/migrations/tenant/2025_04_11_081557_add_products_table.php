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
        Schema::connection('tenant')->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('company_id');
            $table->string('product_code')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('measure_unit_id')->nullable();
            $table->integer('product_field_number')->nullable();
            $table->unsignedBigInteger('base_unit_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->boolean('is_vatable')->default(0);
            $table->unsignedBigInteger('product_type_id')->nullable();
            $table->decimal('purchase_rate', 14, 4)->nullable();
            $table->decimal('purchase_rate_vat', 14, 4)->nullable();
            $table->decimal('wholesale_price', 14, 4)->nullable();
            $table->decimal('wholesale_price_vat', 14, 4)->nullable();
            $table->decimal('retail_price_vat', 14, 4)->nullable();
            $table->decimal('retail_price', 14, 4)->nullable();
            $table->decimal('mrp_price', 14, 4)->nullable();
            $table->decimal('minimum_stock', 14, 4)->nullable();
            $table->decimal('wholesale_profit_percent', 14, 4)->nullable();
            $table->decimal('retail_profit_percent', 14, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditFields();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('products');
    }
};
