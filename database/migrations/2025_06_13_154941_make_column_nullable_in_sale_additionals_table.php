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
        Schema::table('sale_additionals', function (Blueprint $table) {
             $table->string('delivery_date')->nullable()->change();
             $table->string('delivery_time')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_additionals', function (Blueprint $table) {
            $table->date('delivery_date')->nullable()->change();
            $table->time('delivery_time')->nullable()->change();

        });
    }
};
