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
        Schema::create('sale_additionals', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->foreignID('sale_id')->constrained('sales');
            $table->string('place')->nullable();
            $table->string('transport')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('dispatch_code');
            $table->string('driver_contact_number')->nullable();
            $table->date('delivery_date')->nullable();
            $table->time('delivery_time')->nullable();
                  
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_additionals');
    }
};
