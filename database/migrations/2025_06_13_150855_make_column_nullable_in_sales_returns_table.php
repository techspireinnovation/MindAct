<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations (make nullable + set null on delete).
     */
    public function up(): void
    {
        // Drop existing foreign keys if they exist
        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_location_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {}

        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_store_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {}

        // Modify columns to be nullable
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->unsignedBigInteger('store_id')->nullable()->change();
        });

        // Re-add foreign keys with ON DELETE SET NULL
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations (make NOT NULL + restore keys).
     */
    public function down(): void
    {
        // Drop foreign keys
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['store_id']);
        });

        // Ensure there are no NULLs before setting NOT NULL constraint
        // Replace with a valid default ID from your `locations` and `stores` tables
        DB::statement('UPDATE sales_returns SET location_id = 1 WHERE location_id IS NULL');
        DB::statement('UPDATE sales_returns SET store_id = 1 WHERE store_id IS NULL');

        // Change back to NOT NULL
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
        });

        // Re-add original foreign keys
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('store_id')->references('id')->on('stores');
        });
    }
};