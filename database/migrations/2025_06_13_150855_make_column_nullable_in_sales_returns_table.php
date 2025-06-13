<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign keys safely before changing columns
        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_location_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key might not exist yet – ignore
        }

        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_store_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key might not exist yet – ignore
        }

        Schema::table('sales_returns', function (Blueprint $table) {
            // Change columns to nullable
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->unsignedBigInteger('store_id')->nullable()->change();

            // Recreate foreign keys
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['store_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            // Change columns back to not nullable
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->unsignedBigInteger('store_id')->nullable(false)->change();

            // Re-add foreign keys without onDelete('set null')
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('store_id')->references('id')->on('stores');
        });
    }
};
