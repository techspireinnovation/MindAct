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
        Schema::table('sales_return_products', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();
            $table->string('free_quantity')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_return_products', function (Blueprint $table) {
            $table->double('quantity')->nullable()->change();
            $table->double('free_quantity')->nullable()->change();
        });
    }
};
