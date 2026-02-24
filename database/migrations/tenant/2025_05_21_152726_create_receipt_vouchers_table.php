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
        Schema::connection('tenant')->create('receipt_vouchers', function (Blueprint $table) {
            $table->id();

            $table->date('date_ad')->nullable();
            $table->date('date_bs')->nullable();
            $table->string('receipt_voucher_number')->nullable();
            $table->string('reference_number')->nullable();

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('receipt_vouchers');
    }
};
