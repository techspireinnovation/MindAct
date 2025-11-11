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
        Schema::connection('tenant')->create('purchase_return_product_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->constrained('purchases')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('purchase_bill_number')->nullable();

            $table->unsignedBigInteger('product_id')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_return_product_details');
    }
};
