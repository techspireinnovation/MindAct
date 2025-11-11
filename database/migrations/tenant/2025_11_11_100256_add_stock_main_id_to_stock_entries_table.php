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
        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_main_id')->nullable()->after('id');
            $table->foreign('stock_main_id')->references('id')->on('stock_mains')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->dropForeign(['stock_main_id']);
            $table->dropColumn('stock_main_id');
        });
    }
};
