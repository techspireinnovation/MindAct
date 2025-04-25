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
        Schema::create('sales_return_products', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('item_id')->constrined('products');
            $table->string('information')->nullable();
            $table->date('expiry_date')->nullable();
            $table->double('quantity')->nullable();
            $table->foreignID('meaure_unit_id')->constrained('measure_units');
            $table->double('rate');
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->boolean('is_active')->default(true);
            $table->SoftDeletes();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_return_products');
    }
};
