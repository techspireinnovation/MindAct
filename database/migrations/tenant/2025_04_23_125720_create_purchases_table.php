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
        Schema::connection('tenant')->create('purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_year_id')->nullable();
            $table->unsignedBigInteger('branch_id')->constrained('branches')->onDelete('cascade');
            $table->unsignedBigInteger('party_id')->nullable();
            
            $table->string('batch_no')->nullable();
            $table->string('ref_bill_number')->nullable();
            $table->string('document_number')->nullable();
            $table->string('purchase_type')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('purchase_bill_number')->nullable();
           
            $table->string('quantity')->nullable();
            $table->string('free_quantity')->nullable();
            $table->foreignID('store_id')->constrained('stores')->nullable();
            $table->foreignID('location_id')->constrained('locations')->nullable();
            $table->enum('discount_type', ['percent', 'amount'])->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('discount_after_vat',15,2)->nullable();
            $table->decimal('sub_total_before_discount',15,2)->nullable();
            $table->decimal('taxable_amount',15,2)->nullable();
            $table->decimal('non_taxable_amount',15,2)->nullable();
            $table->decimal('excise_duty',15,2)->nullable();
            $table->decimal('health_insurance',15,2)->nullable();
            $table->decimal('freight_amount',15,2)->nullable();
           
            $table->string('roundoff_type')->nullable();
            $table->decimal('roundoff_amount',15,2)->nullable();
            $table->decimal('total_amount',15,2)->nullable();
            $table->string('remarks')->nullable();
            $table->auditFields();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchases');
    }
};
