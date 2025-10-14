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
        Schema::connection('tenant')->table('purchase_returns', function (Blueprint $table) {
            $table->string('roundoff_type')->nullable()->after('roundoff_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn('roundoff_type');
        });
    }
};
