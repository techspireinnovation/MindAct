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
        Schema::connection('tenant')->create('stock_reconciliation_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('stock_reconciliation_id')->constrained()->nullable();
            $table->foreignId('product_id')->constrained('products')->nullable();
            $table->string('product_name')->nullable();
            $table->double('available_stock')->nullable();
            $table->double('physical_stock')->nullable();
           
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_reconciliation_details');
    }
};
