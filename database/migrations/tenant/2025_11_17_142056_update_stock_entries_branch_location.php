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
        Schema::table('stock_entries', function (Blueprint $table) {
            // Make branch_id NOT NULL
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();

            // Make location_id nullable
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            // Revert changes
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }
};
