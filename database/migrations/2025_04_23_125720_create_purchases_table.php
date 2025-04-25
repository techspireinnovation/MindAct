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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('customer_id')->constrained('customers');
            $table->double('balance')->nullable();
            $table->string('batch_no')->nullable();
            $table->string('ref_bill_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('purchase_bill_number')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignID('store_id')->constrained('stores');
            $table->foreignID('location_id')->constrained('locations');
            $table->double('discount_amount')->nullable();
            $table->double('excise_duty')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_amount')->nullable();
            $table->double('discount_after_vat')->nullable();
            $table->double('roundoff_amount')->nullable();
            $table->enum('payment_type', ['cash', 'bank', 'credit'])->default('credit');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
