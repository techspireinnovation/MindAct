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
        Schema::create('purchase_stock_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->unsignedBigInteger('stock_product_id')->nullable();
            $table->foreignID('customer_id')->nullable();

            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('branch_id')->nullable();
            $table->unsignedBigInteger(column: 'purchase_id')->nullable();
            $table->foreignID(column: 'product_id')->constrained('products');
            $table->text('product_code')->constrained('products');
            $table->text(column: 'product_name')->constrained('products');
            $table->date('expiry_date')->nullable();
            $table->double('quantity')->nullable();
            $table->double('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->double('amount')->nullable();
            $table->boolean('is_vatable')->nullable();
            $table->foreignID(column: 'measure_unit_id')->constrained('measure_units');
            $table->softDeletes();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_stock_products');
    }
};
