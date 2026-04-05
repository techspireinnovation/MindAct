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
        Schema::connection('tenant')->create('sales_master_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->boolean('salesman')->default(false);
            $table->boolean('credit_days')->default(false);
            $table->boolean('balance')->default(false);
            $table->boolean('store')->default(false);
            $table->boolean('location')->default(false);
            $table->boolean('direct_mail_system')->default(false);
            $table->boolean('direct_whatsapp_system')->default(false);
            $table->boolean('bill_type')->default(false);
            $table->boolean('product_code')->default(false);
            $table->boolean('discount_percent')->default(false);
            $table->boolean('free')->default(false);
            $table->boolean('discount')->default(false);
            $table->boolean('excise_duty')->default(false);
            $table->boolean('health_insurance')->default(false);
            $table->boolean('freight_charge')->default(false);
            $table->boolean('discount_after_vat')->default(false);
            $table->boolean('expiry_date')->default(false);
            $table->boolean('batch_no')->default(false);
            $table->boolean('additional')->default(false);

            $table->boolean('discount_amount')->default(false);
            $table->boolean('mfd')->default(false);


            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sales_master_keys');
    }
};
