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
        Schema::connection('tenant')->create('hold_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->unsignedBigInteger(column: 'party_id')->nullable();

            $table->string('customer_name')->nullable();
            $table->string('customer_address')->nullable();
            $table->string('credit_days')->nullable();
            $table->string('promo_disc')->nullable();
            $table->double('balance')->nullable();
            $table->double('bill_amount')->nullable();
            $table->double('hold_discount')->nullable();
            $table->double('final_amount')->nullable();
            $table->double('ic_amount')->nullable();
            $table->double('tender')->nullable();
            $table->double('return')->nullable();
            $table->string('invoice_number');
            $table->string('batch_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('invoice_date_bs')->nullable();
            $table->string('document_number')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('ref_number')->nullable();
            $table->text('pan_number')->nullable();
            $table->string('remarks')->nullable();
            $table->unsignedBigInteger('store_id');
            $table->foreignID('location_id')->constrained('locations')->nullable();

            $table->double('sub_total_before_discount')->nullable();
            $table->double('discount')->nullable();
            $table->double('non_taxable_amount')->nullable();
            $table->double('taxable_amount')->nullable();
            $table->double('excise_duty')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_charge')->nullable();
            $table->double('discount_after_vat')->nullable();
            $table->string('roundoff_type')->nullable();
            $table->double('round_off_amount')->nullable();
            $table->double('total_amount')->nullable();
            $table->double('vat_amount')->nullable();
            $table->json('payment')->nullable();
            // $table->enum('entry_type',['invoice','quotation']);
            $table->string('note')->nullable();
            $table->boolean('is_mail_notify')->default(false);
            $table->boolean('is_vatable')->default(true);
            $table->boolean('abvt')->default(true);
            $table->boolean('is_whatsapp_notify')->default(false);

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('hold_sales');
    }
};
