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
        Schema::table('shrinking_working_losses', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained('branches')->nullable()->after('company_id');
                        $table->dropForeign(['product_id']);
            $table->dropColumn([
                'date_from',
                'date_to',
                'product_id',
                'adjustment_ref_no',
                'product_details',
                'total_purchase_quantity',
                'total_loss_quantity',
                'deleted_at'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shrinking_working_losses', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
            
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->foreignId('product_id')->constrained('products')->nullable();
            $table->text('adjustment_ref_no')->nullable();
            $table->json('product_details')->nullable();
            $table->double('total_purchase_quantity')->nullable();
            $table->double('total_loss_quantity')->nullable();
            $table->softDeletes();
        });
    }
};