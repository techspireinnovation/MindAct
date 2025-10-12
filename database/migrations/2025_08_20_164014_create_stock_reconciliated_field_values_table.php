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
        Schema::create('stock_reconciliated_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
            $table->unsignedBigInteger('stock_reconciliated_id')->nullable();
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable();
            $table->unsignedBigInteger('stock_product_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('product_field_id')->constrained('product_fields');
            $table->unsignedInteger('quantity_index')->nullable();
            $table->string('quantity_type')->nullable();
            $table->foreignId('product_id')->constrained('products');

            $table->string('value');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliated_field_values');
    }
};
