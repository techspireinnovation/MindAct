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
        Schema::connection('tenant')->table('stock_transfers', function (Blueprint $table) {
           
            $table->unsignedBigInteger('branch_id')->after('id')->nullable();
            $table->boolean('accept_status')->default(false)->after('is_active');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn('branch_id');
            $table->dropColumn('accept_status');
        });
    }
};
