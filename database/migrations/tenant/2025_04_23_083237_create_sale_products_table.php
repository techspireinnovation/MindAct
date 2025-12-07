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
        Schema::connection('tenant')->create('sale_products', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('mfd')->nullable();
            $table->date('expiry_date')->nullable();

            $table->string('name');
            $table->foreignID('measure_unit_id')->constrained('measure_units')->nullable();
            $table->double('quantity')->nullable();
            $table->double('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->boolean('is_vatable')->default(true);
            $table->double('amount')->nullable();
            $table->SoftDeletes();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sale_products');
    }
};
