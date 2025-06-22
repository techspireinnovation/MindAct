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
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('customer_id')->constrained('customers');
            $table->unsignedBigInteger('salesman_id');
            $table->text('pan_number')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('credit_days')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('customer_contact')->nullable();
            $table->string('return_bill_no')->nullable();
            $table->string('ref_bill_no')->nullable();                  
            $table->string('invoice_number');
            $table->string('document_number')->nullable();
            $table->string('batch_no')->nullable();
            $table->string('balance')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('invoice_date_bs')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignID('store_id')->constrained('stores');
            $table->foreignID('location_id')->constrained('locations');
            $table->double('sub_total_before_discount')->nullable();
            $table->double('discount')->nullable();
            $table->double('non_taxable_amount')->nullable();
            $table->double('taxable_amount')->nullable();                      
            $table->double('excise_duty')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_amount')->nullable();                                
            $table->double('discount_after_vat')->nullable();           
            $table->double('total_amount')->nullable();
            $table->double('round_of_amount')->nullable();
            $table->json('payment')->nullable();
            $table->SoftDeletes();          
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
