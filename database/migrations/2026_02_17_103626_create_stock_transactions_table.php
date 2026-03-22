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
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->onDelete('cascade');
            $table->foreignId('stock_id')
                ->constrained('stocks')
                ->onDelete('cascade');
            $table->foreignId('stock_product_id')
                ->constrained('stock_products')
                ->onDelete('cascade');
            $table->foreignId('party_id')
                ->constrained('parties')
                ->onDelete('cascade');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type')->nullable();
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');
            $table->string('type')->nullable();
            $table->string('direction')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->unsignedInteger('measure_unit_id')->constrained('measure_units')->onDelete('cascade');
            $table->string('batch_no')->nullable();
            $table->boolean('is_vatable')->default(1)->nullable();
            $table->date('mfd')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('price', 14, 4)->nullable();
            $table->decimal('discount_percent', 14, 4)->nullable();
            $table->decimal('discount_amount', 14, 4)->nullable();
            $table->decimal('amount', 14, 4)->nullable();
            $table->timestamps();
            $table->auditFields();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
