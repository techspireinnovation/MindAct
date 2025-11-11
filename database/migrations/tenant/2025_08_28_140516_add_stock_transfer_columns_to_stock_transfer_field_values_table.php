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
       Schema::connection('tenant')->table('stock_transfer_field_values', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('id');
            $table->unsignedBigInteger('stock_transfer_details_id')->nullable()->after('stock_transfer_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('stock_transfer_field_values', function (Blueprint $table) {
            $table->dropColumn(['stock_transfer_id', 'stock_transfer_details_id']);
        });
    }
};
