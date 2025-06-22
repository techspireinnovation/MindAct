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
    // Fix existing orphaned data using LEFT JOIN (more reliable than NOT IN)
    DB::statement("
        UPDATE sales_returns sr
        LEFT JOIN customers c ON sr.customer_id = c.id
        SET sr.customer_id = NULL
        WHERE sr.customer_id IS NOT NULL AND c.id IS NULL
    ");

    DB::statement("
        UPDATE sales_returns sr
        LEFT JOIN salesmen s ON sr.salesman_id = s.id
        SET sr.salesman_id = NULL
        WHERE sr.salesman_id IS NOT NULL AND s.id IS NULL
    ");

    // Drop foreign keys safely
    try {
        DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_customer_id_foreign');
    } catch (\Illuminate\Database\QueryException $e) {}

    try {
        DB::statement('ALTER TABLE sales_returns DROP FOREIGN KEY sales_returns_salesman_id_foreign');
    } catch (\Illuminate\Database\QueryException $e) {}

    // Alter columns and re-add foreign keys
    Schema::table('sales_returns', function (Blueprint $table) {
        $table->unsignedBigInteger('customer_id')->nullable()->change();
        $table->unsignedBigInteger('salesman_id')->nullable()->change();

        $table->foreign('customer_id')
              ->references('id')->on('customers')
              ->onDelete('set null');

        $table->foreign('salesman_id')
              ->references('id')->on('salesmen')
              ->onDelete('set null');
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['salesman_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            $table->unsignedBigInteger('salesman_id')->nullable(false)->change();

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('salesman_id')->references('id')->on('salesmen');
        });
    }
};