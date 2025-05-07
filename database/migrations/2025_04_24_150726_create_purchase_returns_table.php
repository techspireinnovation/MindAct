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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('customer_id')->constrained('customers');
            $table->foreignId('purchase_id')->constrained('purchases');
            $table->double('balance')->nullable();
            $table->string('batch_no')->nullable();
            $table->string('ref_bill_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('purchase_bill_number')->nullable();
            $table->string('remarks')->nullable();
          
            $table->enum('reason', ['damaged', 'defective', 'incorrect', 'expired', 'other']);
            $table->foreignID('store_id')->constrained('stores');
            $table->foreignID('location_id')->constrained('locations');
            $table->double('discount_amount')->nullable();
            $table->double('excise_duty')->nullable();
            $table->double('health_insurance')->nullable();
            $table->double('freight_amount')->nullable();
            $table->double('discount_after_vat')->nullable();
            $table->double('roundoff_amount')->nullable();
            $table->json('payment')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       // Disable foreign key constraints
    Schema::disableForeignKeyConstraints();
    
    // Drop the table
    Schema::dropIfExists('purchase_returns');
    
    // Re-enable foreign key constraints
    Schema::enableForeignKeyConstraints();
    }
};
