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
        Schema::connection('tenant')->create('receipt_voucher_details', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('receipt_voucher_id')->constrained('receipt_vouchers')->nullable();
            $table->string('party_name')->nullable();
            $table->double('amount')->nullable();
            $table->enum('contra_account', ['cash', 'bank'])->nullable();
            $table->string('remarks')->nullable();
            $table->string('cheque_slip')->nullable();
            $table->double('remaining_balance')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('receipt_voucher_details');
    }
};
