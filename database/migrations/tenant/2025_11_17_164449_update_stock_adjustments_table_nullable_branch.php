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
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();

            // Add branch_id column (NOT NULL)
            $table->unsignedBigInteger('branch_id')->nullable(false)->after('id'); // adjust 'after' as needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();

            // Drop branch_id column
            $table->dropColumn('branch_id');
        });
    }
};
