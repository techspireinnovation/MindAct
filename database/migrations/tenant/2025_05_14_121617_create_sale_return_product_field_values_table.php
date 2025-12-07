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
        Schema::connection('tenant')->create('sale_return_product_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_product_id');
            $table->foreignId('product_field_id')->constrained('product_fields');
            $table->string('value');
            $table->integer('quantity_index');
            $table->foreignId('product_id')->constrained('products');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sale_return_product_field_values');
    }
};
