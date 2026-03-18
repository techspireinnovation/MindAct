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
        Schema::connection('tenant')->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('company_id');
            $table->string('product_code')->nullable();
            $table->string('sku')->unique();

            $table->text('note')->nullable();


            $table->unsignedBigInteger('category_id')->nullable();

            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('measure_unit_id')->nullable();

            $table->boolean('is_vatable')->default(0);
            $table->unsignedBigInteger('product_type_id')->nullable();



            $table->boolean('is_active')->default(true);
            $table->auditFields();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('products');
    }
};
