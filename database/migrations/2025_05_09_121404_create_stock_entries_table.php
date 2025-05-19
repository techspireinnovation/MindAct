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
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignID('product_id')->constrained('products');
            $table->text('product_code')->nullable();
            $table->string('product_name')->nullable();
            $table->foreignID('company_id')->constrained('companies');
            
            $table->foreignID('uom')->constrained('measure_units');
            $table->longText('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->double('quantity')->nullable();
            $table->double('rate')->nullable();
            $table->double('amount')->nullable();
            $table->foreignID('location_id')->constrained('locations');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_entries');
    }
};
