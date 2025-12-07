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
        Schema::connection('tenant')->create('sale_return_additionals', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('sales_return_id')->constrained('sales_returns');
            $table->string('place')->nullable();
            $table->string('transport')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('return_code');
            $table->string('driver_contact_number')->nullable();
            $table->date('return_date')->nullable();
            $table->time('return_time')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sale_return_additionals');
    }
};
