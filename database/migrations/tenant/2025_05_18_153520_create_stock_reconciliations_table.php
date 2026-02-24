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
        Schema::connection('tenant')->create('stock_reconciliations', function (Blueprint $table) {
            $table->id();

            $table->date('date_ad')->nullable();
            $table->date('date_bs')->nullable();
            $table->text('reconciliation_no')->nullable();
            $table->text('document_no')->nullable();
            $table->foreignID('branch_id')->constrained('branches');
            $table->json('product_details')->nullable();
            $table->string('remarks')->nullable();

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_reconciliations');
    }
};
