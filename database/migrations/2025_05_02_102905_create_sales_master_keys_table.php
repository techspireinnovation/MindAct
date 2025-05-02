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
        Schema::create('sales_master_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->boolean('salesman')->default(false);
            $table->boolean('product_code')->default(false);
            $table->boolean('free')->default(false);
            $table->boolean('expiry_date')->default(false);
            $table->boolean('batch_no')->default(false);
            $table->boolean('discount_percent')->default(false);
            $table->boolean('discount_amount')->default(false);
           
            $table->boolean('excise_duty')->default(false);
            $table->boolean('health_insurance')->default(false);
            $table->boolean('freight_charge')->default(false);
            $table->boolean('discount_after_vat')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_master_keys');
    }
};
