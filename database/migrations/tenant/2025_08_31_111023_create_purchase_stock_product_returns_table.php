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
    Schema::connection('tenant')->create('purchase_stock_product_returns', function (Blueprint $table) {
      $table->id();
      $table->unsignedBigInteger('purchase_stock_return_id')->nullable();
      $table->unsignedBigInteger('purchase_stock_product_id');
      $table->unsignedBigInteger('stock_product_id')->nullable();
      $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
      $table->unsignedBigInteger('stock_transfer_id')->nullable();
      $table->unsignedBigInteger('stock_adjustment_id')->nullable();
      $table->unsignedBigInteger('purchase_product_id')->nullable();

      $table->unsignedBigInteger('product_id');
      $table->unsignedBigInteger('customer_id')->nullable();
      $table->unsignedBigInteger('company_id')->nullable();
      $table->unsignedBigInteger('branch_id')->nullable();
      $table->string('product_name', 255);
      $table->text('purchase_product_code')->nullable();
      $table->string('expiry_date', 20)->nullable();
      $table->string('mfd', 255)->nullable();
      $table->string('quantity')->nullable();
      $table->string('free_quantity')->nullable();
      $table->double('price')->nullable();
      $table->double('discount_percent')->nullable();
      $table->double('discount_amount')->nullable();
      $table->double('amount')->nullable();
      $table->boolean('is_vatable')->nullable();
      $table->unsignedBigInteger('measure_unit_id');
      $table->softDeletes();
      $table->timestamps();

    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::connection('tenant')->dropIfExists('purchase_stock_product_returns');
  }
};
