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
        Schema::connection('tenant')->create('voucher_summary_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('voucher_summary_id')->constrained('voucher_summaries');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->date('date');
            $table->string('date_bs');
            $table->string('voucher_number')->nullable();
            $table->string('particulars')->nullable();
            $table->double('debit')->nullable();
            $table->double('credit')->nullable();
            $table->foreignId('account_head_id')->nullable()->constrained('account_heads');
            $table->string('tr_bill_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->char('type', 50)->nullable();
            $table->string('payment_type', 50)->nullable();
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups');

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('voucher_summary_details');
    }
};
