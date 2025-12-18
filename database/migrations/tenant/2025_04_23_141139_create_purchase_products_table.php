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
        Schema::connection('tenant')->create('purchase_products', function (Blueprint $table) {
            $table->id();
          

            $table->foreignID(column: 'purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignID(column: 'product_id')->constrained('products')->onDelete('cascade');            
            $table->date('expiry_date')->nullable();
            $table->date('mfd')->nullable();
            $table->string('quantity')->nullable();
            $table->string('free_quantity')->nullable();
            $table->decimal('price',15,2)->nullable();
            $table->decimal('discount_percent',15,2)->nullable();
            $table->decimal('discount_amount',15,2)->nullable();
            $table->decimal('amount',15,2)->nullable();
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
        Schema::connection('tenant')->dropIfExists('purchase_products');
    }
};
