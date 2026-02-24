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
        Schema::connection('tenant')->create('bank_vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('cash')->nullable();
            $table->string('remarks')->nullable();
            $table->string('date')->nullable();
            $table->double('balance')->nullable();
            $table->double('balance_dr')->nullable();
            $table->string('voucher_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->double('amount')->nullable();
            $table->enum('options', ['deposit', 'withdrawal', 'transfer'])->nullable();
            $table->foreignID('bank_id')->nullable()->constrained('banks');

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('bank_vouchers');
    }
};
