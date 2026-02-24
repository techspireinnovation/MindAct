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
        Schema::connection('tenant')->create('stock_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_to')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('document_no')->nullable();
            $table->string('current_location')->nullable();
            $table->date('transaction_date_bs')->nullable();
            $table->date('date_ad')->nullable();
            $table->text('remarks')->nullable();
            $table->text('reason_for')->nullable();
            $table->text('product_details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_transfers');
    }
};
