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
        Schema::create('journal_voucher_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('journal_voucher_id')->constrained('journal_vouchers')->nullable();
            $table->foreignID('main_group_id')->constrained('main_groups')->nullable();
            $table->foreignID('account_group_id')->constrained('account_groups')->nullable();
            $table->foreignID('account_head_id')->constrained('account_heads')->nullable();
            $table->foreignID('sub_group_id')->constrained('sub_groups')->nullable();
            $table->string('account_code')->nullable();
            $table->string('particulars')->nullable();
            $table->string('type')->nullable();
            $table->double('debit')->nullable();
            $table->double('credit')->nullable();
            $table->timestamps();
            $table->SoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_voucher_transactions');
    }
};
