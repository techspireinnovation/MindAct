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
        Schema::connection('tenant')->create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_year_id')->nullable();

            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->text('pan_number')->nullable();
            $table->string('address')->nullable();
            $table->string('customer_contact')->nullable();
            $table->double('balance')->nullable();
            $table->string('batch_no')->nullable();
            $table->text('purchase_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('purchase_bill_number')->nullable();
            $table->string('remarks')->nullable();

            $table->enum('reason', ['damaged', 'defective', 'incorrect', 'expired', 'other']);
            $table->foreignID('store_id')->constrained('stores');
            $table->foreignID('location_id')->constrained('locations');
            $table->enum('discount_type', ['percent', 'amount'])->nullable();
            $table->double('discount_value')->nullable();
            $table->double('sub_total_before_discount')->nullable();
            $table->double('non_taxable_amount')->nullable();
            $table->double('taxable_amount')->nullable();
            $table->double('excise_duty')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_amount')->nullable();
            $table->double('discount_after_vat')->nullable();
            $table->double('roundoff_amount')->nullable();
            $table->double('total_amount')->nullable();
            $table->json('payment')->nullable();
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign key constraints
        Schema::connection('tenant')->disableForeignKeyConstraints();

        // Drop the table
        Schema::connection('tenant')->dropIfExists('purchase_returns');

        // Re-enable foreign key constraints
        Schema::connection('tenant')->enableForeignKeyConstraints();
    }
};
