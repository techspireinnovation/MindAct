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
        Schema::connection('tenant')->create('stock_reconciliateds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->unsignedBigInteger('stock_product_id')->nullable();
            $table->string('purchase_type')->nullable();

            $table->string('reconciliated_type');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignID('branch_id')->constrained('branches')->nullable();
            $table->unsignedBigInteger(column: 'purchase_id')->nullable();
            $table->foreignID(column: 'product_id')->constrained('products');
            $table->text('product_code')->constrained('products');
            $table->text(column: 'product_name')->constrained('products');
            $table->date('expiry_date')->nullable();
            $table->string('mfd')->nullable();
            $table->string('quantity')->nullable();
            $table->string('diff_stock')->nullable();
            $table->string('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->double('amount')->nullable();
            $table->boolean('is_vatable')->nullable();
            $table->foreignID(column: 'measure_unit_id')->constrained('measure_units');
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_reconciliateds');
    }
};
