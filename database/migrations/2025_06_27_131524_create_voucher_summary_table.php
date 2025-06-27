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
        Schema::create('voucher_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->date('date');
            $table->string('date_bs');
            $table->string('voucher_number')->nullable();
            $table->string('particulars')->nullable();
            $table->double('debit')->nullable();
            $table->double('credit')->nullable();
            $table->foreignId('account_head_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_summary');
    }
};
