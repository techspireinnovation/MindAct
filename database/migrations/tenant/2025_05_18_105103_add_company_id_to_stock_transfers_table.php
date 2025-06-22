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
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignID('company_id')->constrained('companies')->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
              $table->dropColumn('company_ld');
        });
    }
};
