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
        Schema::table('sales_returns', function (Blueprint $table) {
            // Step 1: Drop foreign key constraints
            $table->dropForeign(['store_id']);
            $table->dropForeign(['location_id']);

            // Step 2: Make the columns nullable
            $table->foreignId('store_id')->nullable()->change();
            $table->foreignId('location_id')->nullable()->change();

            // Step 3: Re-add foreign key constraints
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            // Drop the updated foreign keys
            $table->dropForeign(['store_id']);
            $table->dropForeign(['location_id']);

            // Revert to not nullable
            $table->foreignId('store_id')->nullable(false)->change();
            $table->foreignId('location_id')->nullable(false)->change();

            // Re-add original foreign keys
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};

