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
        Schema::connection('tenant')->create('stock_receive_details', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('stock_receive_id')->nullable();

            $table->unsignedBigInteger('measure_unit_id')->nullable();
            $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();



            $table->unsignedBigInteger('purchase_id')->nullable();

            $table->string('quantity')->nullable();

            $table->double('amount')->nullable();

            $table->string('mfd')->nullable();

            $table->string('purchase_type')->nullable();


            $table->string('product_code')->nullable();

            $table->string('expiry_date')->nullable();
            $table->string('free_quantity')->nullable();

            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();

            $table->boolean('is_vatable')->default(false);

            $table->unsignedBigInteger('product_id')->constrained('products')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
          
         
            $table->string('batch_no')->nullable();
            $table->double(column: 'price')->nullable();
          
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_receive_details');
    }
};
