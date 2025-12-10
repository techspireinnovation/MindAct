<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {

        try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_store_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {

        }
        try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_location_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {

        }

       Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
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
        Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            // Drop foreign key constraints using raw SQL
            try {
                DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_store_id_foreign');
            } catch (\Illuminate\Database\QueryException $e) {
                // Ignore if constraint doesn't exist
            }
            try {
                DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchases_location_id_foreign');
            } catch (\Illuminate\Database\QueryException $e) {
                // Ignore if constraint doesn't exist
            }

            // Handle NULL values by setting to a default valid ID
            $defaultStoreId = DB::table('stores')->value('id') ?? 1; // Get first store ID or fallback to 1
            $defaultLocationId = DB::table('locations')->value('id') ?? 1; // Get first location ID or fallback to 1

            DB::table('purchases')
                ->whereNull('store_id')
                ->update(['store_id' => $defaultStoreId]);
            DB::table('purchases')
                ->whereNull('location_id')
                ->update(['location_id' => $defaultLocationId]);

           
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();

           
            $table->foreign('store_id')->references('id')->on('stores');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};






