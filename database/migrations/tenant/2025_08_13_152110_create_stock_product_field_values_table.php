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
        Schema::connection('tenant')->create('stock_product_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('product_field_id')->constrained('product_fields');
            $table->unsignedInteger('quantity_index')->nullable();

            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('stock_product_id')->constrained('stock_entries');
            $table->string('value');

            $table->auditFields();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_product_field_values');
    }
};
