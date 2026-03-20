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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->onDelete('cascade');
            $table->unsignedBigInteger('company_id');
            $table->foreignId('bank_id')
                ->constrained('banks')
                ->onDelete('cascade');
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->onDelete('cascade');
            $table->foreignId('party_id')
                ->constrained('parties')
                ->onDelete('cascade');

            $table->foreignId('store_id')
                ->constrained('stores')
                ->onDelete('cascade');

            $table->foreignId('location_id')
                ->constrained('locations')
                ->onDelete('cascade');
            $table->string('type')->nullable();
            $table->string('batch_no')->nullable();
            $table->decimal('credit_days', 14, 4)->nullable();
            $table->decimal('balance', 14, 4)->nullable();
            $table->string('bill_number')->nullable();
            $table->string('purchase_bill_number')->nullable();
            $table->string('ref_bill_number')->nullable();
            $table->string('return_bill_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_date_bs')->nullable();
            $table->string('purchase_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('reasons')->nullable();
            $table->string('dscount_type')->nullable();
            $table->decimal('discount_value', 14, 4)->nullable();
            $table->decimal('discount_after_vat', 14, 4)->nullable();
            $table->decimal('sub_total_before_discount', 14, 4)->nullable();
            $table->decimal('discount_after_vat', 14, 4)->nullable();
            $table->decimal('taxable_amount', 14, 4)->nullable();
            $table->decimal('non_taxable_amount', 14, 4)->nullable();
            $table->decimal('excise_duty', 14, 4)->nullable();
            $table->decimal('vat_percent', 14, 4)->nullable();
            $table->decimal('health_insurance', 14, 4)->nullable();
            $table->decimal('freight_amount', 14, 4)->nullable();
            $table->string('roundoff_type')->nullable();
            $table->decimal('roundoff_amount', 14, 4)->nullable();
            $table->decimal('total_amount', 14, 4)->nullable();
            $table->text('payment')->nullable();
            $table->text('payment')->nullable();
            $table->boolean('bill_active')->nullable();
            $table->boolean('sync_with_ird')->nullable();
            $table->boolean('sales_bill_print')->nullable();
            $table->time('sales_bill_print_time')->nullable();
            $table->text('preview_count')->nullable();

            $table->timestamps();
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
