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
        Schema::create('transaction_pivots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->onDelete('cascade');


            $table->foreignId('stock_product_id')->nullable()
                ->constrained('stock_products')
                ->onDelete('cascade');

            $table->foreignId('stock_transaction_id')->nullable()
                ->constrained('stock_transactions')
                ->onDelete('cascade');

            $table->foreignId('stock_movement_id')->nullable()
                ->constrained('stock_movements')
                ->onDelete('cascade');

            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');
            $table->string('quantity_index')->nullable();
            
            $table->string('quantity_type')->nullable();

            $table->string('direction')->nullable();

            $table->string('type')->nullable();
            
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_pivots');
    }
};
