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
        Schema::create('purchase_stock_returns', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->string('customer_name', 255)->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->string('invoice_number', 255)->nullable();
            $table->text('pan_number')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('customer_contact', 255)->nullable();
            $table->double('balance')->nullable();
            $table->string('batch_no', 255)->nullable();
            $table->text('purchase_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_date_bs', 20)->nullable();
            $table->string('purchase_bill_number', 255)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->enum('reason', ['damaged', 'defective', 'incorrect', 'expired']);

            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->enum('discount_type', ['percent', 'amount'])->nullable();
            $table->double('discount_value')->nullable();
            $table->double('sub_total_before_discount')->nullable();
            $table->double('non_taxable_amount')->nullable();
            $table->double('taxable_amount')->nullable();
            $table->double('excise_duty')->nullable();
            $table->double('vat_percent')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_amount')->nullable();
            $table->double('discount_after_vat')->nullable();
            $table->double('roundoff_amount')->nullable();
            $table->string('roundoff_type', 255)->nullable();
            $table->double('total_amount')->nullable();
            $table->longText('payment')->nullable();
            $table->string('purchase_return_type', 255)->nullable();
            $table->softDeletes(); 
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_stock_returns');
    }
};
