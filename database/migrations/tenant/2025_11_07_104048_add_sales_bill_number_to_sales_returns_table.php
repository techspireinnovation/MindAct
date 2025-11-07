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
        Schema::table('sales_returns', function (Blueprint $table) {
             $table->string('sales_bill_number')->nullable()->after('invoice_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
               $table->dropColumn('sales_bill_number');
        });
    }
};
