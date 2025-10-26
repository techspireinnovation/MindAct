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
            $table->string('quantity')->nullable()->change();
            $table->string('free_quantity')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->table('purchase_products', function (Blueprint $table) {
            DB::table('purchase_products')
                ->whereNull('quantity')
                ->update(['quantity' => '0']);
            DB::table('purchase_products')
                ->whereNull('free_quantity')
                ->update(['free_quantity' => '0']);

            // Handle non-numeric values by setting to 0
            DB::table('purchase_products')
                ->whereRaw('quantity NOT REGEXP ?', ['^[0-9]+\\.?[0-9]*$'])
                ->update(['quantity' => '0']);
            DB::table('purchase_products')
                ->whereRaw('free_quantity NOT REGEXP ?', ['^[0-9]+\\.?[0-9]*$'])
                ->update(['free_quantity' => '0']);
            $table->double('quantity')->change();
            $table->double('free_quantity')->change();
        });
    }
};
