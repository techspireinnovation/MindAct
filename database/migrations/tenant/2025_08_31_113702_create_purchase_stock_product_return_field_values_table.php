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
        Schema::connection('tenant')->create('purchase_stock_product_return_field_values', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('purchase_stock_product_return_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->nullable();
            $table->unsignedBigInteger('stock_product_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->unsignedBigInteger('stock_transfer_id')->nullable();
            $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
            $table->unsignedBigInteger('product_field_id');
            $table->string('value', 255);
            $table->integer('quantity_index');
            $table->string('quantity_type', 255)->nullable();
            $table->unsignedBigInteger('product_id');


            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_stock_product_return_field_values');
    }
};
