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
    Schema::table('purchases', function (Blueprint $table) {
        // Use DB::statement to drop foreign keys safely
        DB::statement('ALTER TABLE purchases DROP FOREIGN KEY IF EXISTS purchases_store_id_foreign');
        DB::statement('ALTER TABLE purchases DROP FOREIGN KEY IF EXISTS purchases_location_id_foreign');
    });

    Schema::table('purchases', function (Blueprint $table) {
        // Modify columns to be nullable
        $table->unsignedBigInteger('store_id')->nullable()->change();
        $table->unsignedBigInteger('location_id')->nullable()->change();

        // Re-add foreign keys
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

            // Make columns non-nullable again
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();

            // Re-add foreign keys
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};
