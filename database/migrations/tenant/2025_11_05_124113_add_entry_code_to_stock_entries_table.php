<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->string('entry_code')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('stock_entries', function (Blueprint $table) {
            $table->dropColumn('entry_code');
        });
    }
};
