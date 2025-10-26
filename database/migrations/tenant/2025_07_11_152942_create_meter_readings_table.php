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
        Schema::connection('tenant')->create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nozzle_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('opening_reading')->nullable();
            $table->string('sale_litres')->nullable();
            $table->string('closing_reading')->nullable();
            $table->string('due_sale_litre')->nullable();
            $table->string('type_of_fuel')->nullable();
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
        Schema::connection('tenant')->dropIfExists('meter_readings');
    }
};
