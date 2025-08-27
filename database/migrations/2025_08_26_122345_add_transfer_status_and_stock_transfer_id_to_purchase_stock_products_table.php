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
        Schema::table('purchase_stock_products', function (Blueprint $table) {
            $table->string('transfer_status')->nullable()->after('purchase_type');
            $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('transfer_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_stock_products', function (Blueprint $table) {
             $table->dropColumn(['transfer_status', 'stock_transfer_id']);
        });
    }
};
