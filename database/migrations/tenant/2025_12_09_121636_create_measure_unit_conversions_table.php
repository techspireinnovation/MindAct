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
        Schema::create('measure_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');
            $table->foreignId('from_unit_id')
                ->constrained('measure_units')
                ->onDelete('cascade');

            $table->foreignId('to_unit_id')
                ->constrained('measure_units')
                ->onDelete('cascade');

            $table->decimal('conversion_factor', 15, 6);
            $table->boolean('is_active')->default(true);
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('measure_unit_conversions');
    }
};
