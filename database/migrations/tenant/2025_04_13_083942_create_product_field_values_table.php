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
       Schema::connection('tenant')->create('product_field_values', function (Blueprint $table) {
            $table->id();
            
            $table->foreignID('product_field_id')->constrained('product_fields');
            $table->foreignID('product_id')->constrained('products');
            $table->string('value');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('product_field_values');
    }
};
