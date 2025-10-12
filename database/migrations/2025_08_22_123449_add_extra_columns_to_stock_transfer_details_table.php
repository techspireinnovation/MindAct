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
        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->string('quantity')->nullable()->change();

            // New columns
            $table->unsignedBigInteger('stock_adjustment_id')->nullable()->after('id');
            $table->unsignedBigInteger('stock_reconciliation_id')->nullable()->after('stock_adjustment_id');
            $table->unsignedBigInteger('branch_id')->nullable()->after('stock_reconciliation_id');
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable()->after('branch_id');

            $table->string('mfd')->nullable()->after('purchase_stock_product_id'); // keep string if you store BS
            $table->string('purchase_type')->nullable()->after('mfd');

            $table->unsignedBigInteger('purchase_product_id')->nullable()->after('purchase_type');
            $table->unsignedBigInteger('stock_product_id')->nullable()->after('purchase_product_id');
            $table->unsignedBigInteger('purchase_id')->nullable()->after('stock_product_id');

            $table->string('product_code')->nullable()->after('purchase_id');
            $table->string('expiry_date')->nullable()->after('product_code');

            $table->string('free_quantity')->nullable()->after('expiry_date');
            $table->double('discount_percent')->nullable()->after('free_quantity');
            $table->double('discount_amount')->nullable()->after('discount_percent');

            $table->boolean('is_vatable')->default(false)->after('discount_amount');
            $table->unsignedBigInteger('measure_unit_id')->nullable()->after('is_vatable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfer_details', function (Blueprint $table) {
            $table->double('quantity')->nullable()->change();

           
            $table->dropColumn([
                'stock_adjustment_id',
                'stock_reconciliation_id',
                'branch_id',
                'purchase_stock_product_id',
                'mfd',
                'purchase_type',
                'purchase_product_id',
                'stock_product_id',
                'purchase_id',
                'product_code',
                'expiry_date',
                'free_quantity',
                'discount_percent',
                'discount_amount',
                'is_vatable',
                'measure_unit_id',
            ]);
        });
    }
};
