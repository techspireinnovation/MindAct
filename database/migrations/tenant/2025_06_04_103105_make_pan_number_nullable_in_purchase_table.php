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
       Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            $table->string('pan_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            DB::table('purchases')
                ->whereNull('pan_number')
                ->update(['pan_number' => '']);
            $table->string('pan_number')->nullable(false)->change();
        });
    }
};
