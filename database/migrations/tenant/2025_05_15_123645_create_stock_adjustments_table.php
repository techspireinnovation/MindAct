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
    Schema::connection('tenant')->create('stock_adjustments', function (Blueprint $table) {
      $table->id();
      $table->string('reference_no')->nullable();
      $table->date('invoice_date')->nullable();
      $table->date('invoice_date_bs')->nullable();
      $table->text('document_number')->nullable();
      $table->foreignID('location_id')->constrained('locations');
      $table->text('remarks')->nullable();
      $table->text('reasons')->nullable();
      $table->string('product_details')->nullable();
      $table->unsignedBigInteger('company_id')->nullable();
      $table->softDeletes();
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::connection('tenant')->dropIfExists('stock_adjustments');
  }
};
