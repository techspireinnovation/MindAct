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
        Schema::create('stock_receive_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->constrained('products')->nullable();
            $table->unsignedBigInteger('company_id')->constrained('companies')->nullable();
            $table->string('product_name')->nullable();
            $table->double('quantity')->nullable();
            $table->double('measure_unit_id')->nullable();
            $table->string('batch_no')->nullable();
            $table->double(column: 'price')->nullable();
            $table->double(column: 'amount')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_receive_details');
    }
};
