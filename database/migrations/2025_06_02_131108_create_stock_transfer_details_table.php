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
        Schema::create('stock_transfer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignID('stock_transfer_id')->constrained('stock_transfers')->nullable();
            $table->foreignID('company_id')->constrained('companies')->nullable();
            $table->string('product_id')->constrained('products')->nullable();
            $table->string('product_name')->nullable();
            $table->double('quantity')->nullable();
            $table->foreignId('unit')->nullable();
            $table->string('batch_no')->nullable();
            $table->double('price')->nullable();
            $table->double('amount')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_details');
    }
};
