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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->string('mrn_number');
            $table->string('bill_number')->nullable();
            $table->string('pan_vat_number')->nullable();
            $table->date('mrn_date');
            $table->date('bill_date');
            $table->foreignID('supplier_id')->constrained('suppliers');
            $table->foreignID('location_id')->constrained('locations');
            $table->double('discount_percent')->nullable();
            $table->double('discount_percent_vat')->nullable();
            $table->double('discount_amount_vat')->nullable();
            $table->double('discount_amount')->nullable();
            $table->double('roundoff_amount')->nullable();
            $table->enum('payment_type', ['cash', 'bank', 'credit']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
