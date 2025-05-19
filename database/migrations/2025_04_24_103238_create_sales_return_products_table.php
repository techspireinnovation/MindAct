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
        Schema::create('sales_return_products', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('sales_return_id')->constrained('sales_returns');
            $table->foreignID('sale_product_id')->constrained('sale_products');
            $table->foreignID('purchase_product_id')->nullalbe();
            $table->foreignID('product_id')->constrained('products');
            $table->string('product_code')->nullable();
            $table->string('product_name')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('mfd')->nullable();
            $table->double('quantity')->nullable();
            $table->double('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->text('batch_no')->nullable();
            $table->boolean('is_vatable')->nullable();
            $table->foreignID('measure_unit_id')->constrained('measure_units');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_return_products');
    }
};
