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
            $table->foreignId('bank_id')->nullable()
                ->constrained('banks')
                ->onDelete('cascade');
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->onDelete('cascade');
            $table->foreignId('party_id')->nullable()
                ->constrained('parties')
                ->onDelete('cascade');

            $table->foreignId('store_id')->nullable()
                ->constrained('stores')
                ->onDelete('cascade');

            $table->foreignId('location_id')->nullable()
                ->constrained('locations')
                ->onDelete('cascade');
            $table->string('type')->nullable();
            $table->string('batch_no')->nullable();
            $table->decimal('credit_days', 15, 2)->nullable();
            $table->decimal('balance', 15, 2)->nullable();
            $table->string('bill_number')->nullable();
            $table->string('purchase_bill_number')->nullable();
            $table->string('ref_bill_number')->nullable();
            $table->string('return_bill_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_date_bs')->nullable();
            $table->string('purchase_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('reasons')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('discount_after_vat', 15, 2)->nullable();
            $table->decimal('sub_total_before_discount', 15, 2)->nullable();

            $table->decimal('taxable_amount', 15, 2)->nullable();
            $table->decimal('non_taxable_amount', 15, 2)->nullable();
            $table->decimal('excise_duty', 15, 2)->nullable();
            $table->decimal('vat_percent', 15, 2)->nullable();
            $table->decimal('health_insurance', 15, 2)->nullable();
            $table->decimal('freight_amount', 15, 2)->nullable();
            $table->string('roundoff_type')->nullable();
            $table->decimal('roundoff_amount', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->boolean('bill_active')->nullable();
            $table->boolean('sync_with_ird')->nullable();
            $table->boolean('sales_bill_print')->nullable();
            $table->time('sales_bill_print_time')->nullable();
            $table->text('preview_count')->nullable();
            $table->text('payment')->nullable();
            $table->text('remarks')->nullable();



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
