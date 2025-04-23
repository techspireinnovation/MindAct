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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->unsignedBigInteger('store_id');
            $table->enum('entry_type',['invoice','quotation']);
            $table->string('note')->nullable();
            $table->string('invoice_quotation_number');
            $table->foreignID('customer_id')->constrained('customers');
          
            $table->string('bill_number')->nullable();
            $table->string('tpin_number')->nullable();
            $table->date('billing_date');
            $table->foreignID('location')->constrained('locations')->nullable();
            $table->enum('sale_rate_type',['retail','wholesale']);
            $table->double('discount')->nullable();
            $table->double('discount_vat')->nullable();
            $table->double('paid_amount')->nullable();
            $table->double('round_of_amount')->nullable();
            $table->enum('payment_type',['cash','credit','bank']);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
