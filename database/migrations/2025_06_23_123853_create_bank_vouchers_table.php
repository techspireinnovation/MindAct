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
        Schema::create('bank_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->string('cash')->nullable();
            $table->string('remarks')->nullable();
            $table->string('date')->nullable();
            $table->double('balance')->nullable();
            $table->double('balance_dr')->nullable();
            $table->string('voucher_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->double('amount')->nullable();
            $table->enum('options', ['deposit', 'withdrawal', 'transfer'])->nullable();
            $table->foreignID('bank_id')->constrained('banks')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_vouchers');
    }
};
