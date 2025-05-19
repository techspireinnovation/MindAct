<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->date('date_ad')->nullable();
            $table->date('date_bs')->nullable();
            $table->text('reconciliation_no')->nullable();
            $table->text('document_no')->nullable();
            $table->foreignID('branch_id')->constrained('branches');
            $table->json('product_details')->nullable();
            $table->string('remarks')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliations');
    }
};
