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
        Schema::connection('tenant')->create('production_settings', function (Blueprint $table) {
            $table->id();

            $table->date('date')->nullable();
            $table->text('document_no')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->double('quantity')->nullable();
            $table->foreignId('measure_unit_id')->constrained('measure_units');
            $table->json('product_details')->nullable();

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('production_settings');
    }
};
