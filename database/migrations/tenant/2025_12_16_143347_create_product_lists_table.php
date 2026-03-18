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
        Schema::create('product_lists', function (Blueprint $table) {
            $table->id();
            
            $table->foreignID('product_id')->constrained('products');
            $table->foreignID('measure_unit_id')->constrained('measure_units');

          
            $table->decimal('price',15,2)->nullable();
            $table->decimal('discount',15,2)->nullable();
            $table->decimal('final_price',15,2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignID('primary_measure_unit_id')->constrained('measure_units');
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_lists');
    }
};
