<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_receive_details', function (Blueprint $table) {

            // Change quantity to string
            if (Schema::hasColumn('stock_receive_details', 'quantity')) {
                $table->string('quantity')->nullable()->change();
            }

            // Add missing columns safely
            if (!Schema::hasColumn('stock_receive_details', 'stock_receive_id')) {
                $table->unsignedBigInteger('stock_receive_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'measure_unit_id')) {
                $table->unsignedBigInteger('measure_unit_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'amount')) {
                $table->double('amount')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'stock_adjustment_id')) {
                $table->unsignedBigInteger('stock_adjustment_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'stock_reconciliation_id')) {
                $table->unsignedBigInteger('stock_reconciliation_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'mfd')) {
                $table->string('mfd')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'purchase_type')) {
                $table->string('purchase_type')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'purchase_product_id')) {
                $table->unsignedBigInteger('purchase_product_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'stock_product_id')) {
                $table->unsignedBigInteger('stock_product_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'purchase_id')) {
                $table->unsignedBigInteger('purchase_id')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'product_code')) {
                $table->string('product_code')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'expiry_date')) {
                $table->string('expiry_date')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'free_quantity')) {
                $table->string('free_quantity')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'discount_percent')) {
                $table->double('discount_percent')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'discount_amount')) {
                $table->double('discount_amount')->nullable();
            }

            if (!Schema::hasColumn('stock_receive_details', 'is_vatable')) {
                $table->boolean('is_vatable')->default(false);
            }

            if (!Schema::hasColumn('stock_receive_details', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_receive_details', function (Blueprint $table) {

            // Change quantity back to double
            if (Schema::hasColumn('stock_receive_details', 'quantity')) {
                $table->double('quantity')->nullable()->change();
            }

            // Drop columns safely
            $columns = [
                'stock_receive_id',
                'measure_unit_id',
                'amount',
                'stock_adjustment_id',
                'stock_reconciliation_id',
                'branch_id',
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
                'deleted_at'
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('stock_receive_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
