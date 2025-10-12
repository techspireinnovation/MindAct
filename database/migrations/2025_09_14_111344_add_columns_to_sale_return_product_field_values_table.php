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
        Schema::table('sale_return_product_field_values', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_product_id')->nullable()->change();
            $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
            $table->unsignedBigInteger('purchase_stock_product_id')->after('branch_id');

            $table->unsignedBigInteger('stock_product_id')->nullable()->after('purchase_stock_product_id');
            $table->unsignedBigInteger('stock_transfer_id')->nullable()->after('stock_product_id');
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable()->after('stock_transfer_id');
            $table->unsignedBigInteger('stock_adjustment_id')->nullable()->after('stock_reconciliation_id');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_product_field_values', function (Blueprint $table) {
            // Safely revert purchase_product_id (remove nullable)
            $table->unsignedBigInteger('purchase_product_id')->nullable(false)->change();

            // Drop each column individually (avoids issues in some DB versions)
            if (Schema::hasColumn('sale_return_product_field_values', 'branch_id')) {
                $table->dropColumn('branch_id');
            }
            if (Schema::hasColumn('sale_return_product_field_values', 'purchase_stock_product_id')) {
                $table->dropColumn('purchase_stock_product_id');
            }
            if (Schema::hasColumn('sale_return_product_field_values', 'stock_product_id')) {
                $table->dropColumn('stock_product_id');
            }
            if (Schema::hasColumn('sale_return_product_field_values', 'stock_transfer_id')) {
                $table->dropColumn('stock_transfer_id');
            }
            if (Schema::hasColumn('sale_return_product_field_values', 'stock_reconciliation_id')) {
                $table->dropColumn('stock_reconciliation_id');
            }
            if (Schema::hasColumn('sale_return_product_field_values', 'stock_adjustment_id')) {
                $table->dropColumn('stock_adjustment_id');
            }
        });
    }
};
