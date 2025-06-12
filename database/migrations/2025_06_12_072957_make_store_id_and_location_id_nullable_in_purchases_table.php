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
        // Attempt to drop foreign keys safely using try-catch
        try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_store_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key might not exist, ignore or log as needed
        }
        try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_location_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Same here
        }
        // Now modify columns and re-add foreign keys
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropForeign(['location_id']);
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};






