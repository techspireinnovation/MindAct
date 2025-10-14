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
       Schema::connection('tenant')->table('purchase_products', function (Blueprint $table) {
            $table->string('purchase_type')->nullable()->after('id');
        });

        Schema::connection('tenant')->table('purchase_stock_products', function (Blueprint $table) {
            $table->string('purchase_type')->nullable()->after('id');
        });

        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->string('purchase_type')->nullable()->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('purchase_products', function (Blueprint $table) {
            $table->dropColumn('purchase_type');
        });

       Schema::connection('tenant')->table('purchase_stock_products', function (Blueprint $table) {
            $table->dropColumn('purchase_type');
        });

        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->dropColumn('purchase_type');
        });
    }
};
