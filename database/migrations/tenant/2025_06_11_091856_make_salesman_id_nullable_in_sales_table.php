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
        Schema::connection('tenant')->table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('salesman_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->table('sales', function (Blueprint $table) {
            $defaultSalesmanId = DB::table('salesmen')->value('id') ?? 1; // Get first salesman ID or fallback to 1
            // If salesman_id references 'users' table instead, use:
            // $defaultSalesmanId = DB::table('users')->value('id') ?? 1;

            DB::table('sales')
                ->whereNull('salesman_id')
                ->update(['salesman_id' => $defaultSalesmanId]);
            $table->unsignedBigInteger('salesman_id')->nullable(false)->change();
        });
    }
};
