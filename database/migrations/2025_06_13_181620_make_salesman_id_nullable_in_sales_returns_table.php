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
        // Drop foreign key safely before changing columns
        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_customer_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key might not exist yet – ignore
        }

        try {
            DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_salesman_id_foreign');
        } catch (\Illuminate\Database\QueryException $e) {
            // Foreign key might not exist yet – ignore
        }

        Schema::table('sales_returns', function (Blueprint $table) {
            // Change columns to nullable
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->unsignedBigInteger('salesman_id')->nullable()->change();

            // Recreate foreign keys
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('salesman_id')->references('id')->on('salesmen')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign keys first
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['salesman_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            // Change columns back to not nullable
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->unsignedBigInteger('salesman_id')->nullable(false)->change();

            // Re-add foreign keys without onDelete('set null')
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('salesman_id')->references('id')->on('salesmen');
        });
    }
};
