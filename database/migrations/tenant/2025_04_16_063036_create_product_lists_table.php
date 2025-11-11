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
        Schema::connection('tenant')->create('product_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignID('product_id')->constrained('products');
            $table->foreignID('measure_unit_id')->constrained('measure_units');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            $table->double('price')->nullable();
            $table->double('discount')->nullable();
            $table->double('final_price')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->foreignID('primary_measure_unit_id')->constrained('measure_units');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('product_lists');
    }
};
