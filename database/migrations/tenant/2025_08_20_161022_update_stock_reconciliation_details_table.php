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
        Schema::connection('tenant')->table('stock_reconciliation_details', function (Blueprint $table) {
            // First rename columns
            $table->renameColumn('available_stock', 'current_stock');
            $table->renameColumn('physical_stock', 'actual_stock');
        });

       Schema::connection('tenant')->table('stock_reconciliation_details', function (Blueprint $table) {
            // Then change datatypes
            $table->string('current_stock')->nullable()->change();
            $table->string('actual_stock')->nullable()->change();

            // Add missing fields
            $table->foreignId('branch_id')->nullable()->after('company_id');
            $table->foreignId('purchase_stock_product_id')->nullable()->after('stock_reconciliation_id');
            $table->string('mfd')->nullable()->after('purchase_stock_product_id');
            $table->foreignId('purchase_product_id')->nullable()->after('mfd');
            $table->foreignId('stock_product_id')->nullable()->after('purchase_product_id');
            $table->foreignId('purchase_id')->nullable()->after('stock_product_id');
            $table->string('purchase_type')->nullable()->after('purchase_id');
            $table->string('product_code')->nullable()->after('product_name');
            $table->string('diff_stock')->nullable()->after('actual_stock');
            $table->string('quantity')->nullable()->after('diff_stock');
            $table->string('free_quantity')->nullable()->after('quantity');
            $table->string('price')->nullable()->after('free_quantity');
            $table->string('discount_percent')->nullable()->after('price');
            $table->string('discount_amount')->nullable()->after('discount_percent');
            $table->string('amount')->nullable()->after('discount_amount');
            $table->boolean('is_vatable')->default(false)->after('amount');
            $table->foreignId('measure_unit_id')->nullable()->after('is_vatable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('stock_reconciliation_details', function (Blueprint $table) {
            // Revert type changes
            $table->double('current_stock')->nullable()->change();
            $table->double('actual_stock')->nullable()->change();

            // Rename back
            $table->renameColumn('current_stock', 'available_stock');
            $table->renameColumn('actual_stock', 'physical_stock');

            // Drop added fields
            $table->dropColumn([
                'branch_id',
                'purchase_stock_product_id',
                'mfd',
                'purchase_product_id',
                'stock_product_id',
                'purchase_id',
                'purchase_type',
                'product_code',
                'diff_stock',
                'quantity',
                'free_quantity',
                'price',
                'discount_percent',
                'discount_amount',
                'amount',
                'is_vatable',
                'measure_unit_id',
            ]);
        });
    }
};
