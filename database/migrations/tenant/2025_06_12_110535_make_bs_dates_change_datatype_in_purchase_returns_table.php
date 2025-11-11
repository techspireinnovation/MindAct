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
         try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchase_returns_store_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
           
        }
        try {
            DB::statement('ALTER TABLE purchases DROP FOREIGN KEY purchase_returns_location_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
           
        }
      
        Schema::connection('tenant')->table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();

        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->table('purchase_returns', function (Blueprint $table) {
       
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();

        });
    }
};
