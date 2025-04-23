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
        Schema::create('sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignId('sale_id')->constrained('sales');
            $table->string('information');
            $table->double('available_quantity')->nullable();
            $table->double('quantity');
            $table->foreignID('uom')->constrained('measure_units')->nullable();
            $table->double('rate');
            $table->integer('total_items')->nullable();
            $table->double('discount')->nullable();
            $table->SoftDeletes();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_products');
    }
};
