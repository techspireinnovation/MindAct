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
        Schema::connection('tenant')->table('sale_products', function (Blueprint $table) {

            $table->unsignedBigInteger('purchase_product_id')->nullable()->change();
            $table->unsignedBigInteger('branch_id')->nullable()->after('id');
            $table->unsignedBigInteger('purchase_stock_product_id')->after('branch_id');
            $table->unsignedBigInteger('stock_product_id')->nullable()->after('purchase_stock_product_id');
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable()->after('stock_product_id');
            $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('stock_reconciliation_id');
            $table->unsignedBigInteger('stock_adjustment_id')->nullable()->after('stock_transfer_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('sale_products', function (Blueprint $table) {
            $table->dropColumn([
                'branch_id',
                'purchase_stock_product_id',
                'stock_product_id',
                'stock_reconciliation_id',
                'stock_transfer_id',
                'stock_adjustment_id',
            ]);
        });
    }
};
