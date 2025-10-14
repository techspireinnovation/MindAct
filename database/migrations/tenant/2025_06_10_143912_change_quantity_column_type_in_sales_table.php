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
       Schema::connection('tenant')->table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('sales', function (Blueprint $table) {
            $defaultStoreId = DB::table('stores')->value('id') ?? 1; // Get first store ID or fallback to 1
            $defaultLocationId = DB::table('locations')->value('id') ?? 1; // Get first location ID or fallback to 1

            DB::table('sales')
                ->whereNull('store_id')
                ->update(['store_id' => $defaultStoreId]);
            DB::table('sales')
                ->whereNull('location_id')
                ->update(['location_id' => $defaultLocationId]);

            
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
    }
};
