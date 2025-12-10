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
        Schema::connection('tenant')->create('hold_sale_product_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreignId('product_field_id')->constrained('product_fields');
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable();
            $table->unsignedInteger('quantity_index')->nullable();
            $table->string('quantity_type')->nullable();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('hold_sale_product_id')->nullable();
            $table->string('value');

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('hold_sale_product_field_values');
    }
};
