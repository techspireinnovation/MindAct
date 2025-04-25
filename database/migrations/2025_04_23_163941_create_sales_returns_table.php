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
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->enum('sale_rate_type',['retail','wholesale']);
            $table->string('return_invoice_number');
            $table->foreignID('customer_id')->constrained('customers');
            $table->string('batch_no')->nullable();
            $table->string('tpin_number')->nullable()->nullable();
            $table->foreignID('sales_id')->constrained('sales');
            $table->foreignID('store_id')->constrained('stores');
            $table->foreignID('location_id')->constrained('locations');
            $table->double('discount_amount')->nullable();
            $table->double('discount_vat')->nullable();
            $table->double('paid_amount')->nullable();
            $table->double('round_of_amount')->nullable();
            $table->enum('payment_type',['cash','credit','bank']);
            $table->text('sales_details')->nullable();
            $table->text('terms')->nullable();
            $table->SoftDeletes();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
