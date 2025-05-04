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
        Schema::create('purchase_product_field_values', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('product_field_id')->constrained('product_fields');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('purchase_product_id')->constrained('purchase_products');
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
        Schema::dropIfExists('purchase_product_field_values');
    }
};
