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
        Schema::create('stock_product_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->nullable();
            $table->foreignId('company_id')->constrained('companies')->nullable();
            $table->string('product_id')->constrained('products')->nullable();
            $table->string('product_name')->nullable();
            $table->string('current_stock')->nullable();
            $table->string('actual_stock')->nullable();
            $table->string('diff_stock')->nullable();
            $table->foreignId('unit_id')->nullable();
            $table->softDeletes();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_product_details');
    }
};
