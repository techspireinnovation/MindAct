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
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('pos_type')->default(0)->after('payment');
            $table->string('promo_disc')->nullable()->after('pos_type');
            $table->double('bill_amount')->nullable()->after('promo_disc');
            $table->double('hold_discount')->nullable()->after('bill_amount');
            $table->double('final_amount')->nullable()->after('hold_discount');
            $table->double('ic_amount')->nullable()->after('final_amount');
            $table->double('tender')->nullable()->after('ic_amount');
            $table->double('return')->nullable()->after('tender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
               $table->dropColumn([
                'promo_disc',
                'bill_amount',
                'hold_discount',
                'final_amount',
                'ic_amount',
                'tender',
                'return',
            ]);
        });
    }
};
