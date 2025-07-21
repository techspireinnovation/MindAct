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
        Schema::create('voucher_inner_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('voucher_summary_id')->constrained('voucher_summaries');
            $table->foreignId('voucher_summary_detail_id')->constrained('voucher_summary_details');
            $table->string('particulars')->nullable();
            $table->double('debit')->nullable();
            $table->double('credit')->nullable();
            $table->foreignId('account_head_id')->nullable()->constrained('account_heads');
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_inner_details');
    }
};
