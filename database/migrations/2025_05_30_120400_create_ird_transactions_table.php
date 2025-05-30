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
        Schema::create('ird_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->text('fiscal_yaer');
            $table->text('bill_no');
            $table->text('customer_name');
            $table->text('customer_pan')->nullable();
            $table->date('bill_date');
            $table->double('amount')->nullable();
            $table->double('discount')->nullable();
            $table->double('discount')->nullable();
            $table->double('taxable_amount')->nullable();
            $table->double('tax_amount')->nullable();
            $table->double('total_amount')->nullable();
            $table->boolean('sync_with_ird')->nullable();
            $table->boolean('is_bill_printed')->nullable();
            $table->boolean('is_bill_active')->nullable();
            $table->date('printed_time')->nullable();
            $table->foreignID('entered_by')->constrained('users')->nullable();
            $table->foreignID('printed_by')->constrained('users')->nullable();
            $table->boolean('is_realtime')->nullable();
            $table->text('transaction_id')->nullable();
            $table->text('payment_method')->nullable();
            $table->double('vat_refund_amount')->nullable();
            $table->SoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ird_transactions');
    }
};
