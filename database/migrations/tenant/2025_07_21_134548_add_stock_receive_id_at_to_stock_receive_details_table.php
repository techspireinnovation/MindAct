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
       Schema::connection('tenant')->table('stock_receive_details', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_receive_id')->nullable()->after('id');
            $table->softDeletes();
            //
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('stock_receive_details', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('stock_receive_details', 'stock_receive_id')) {
                $table->dropColumn('stock_receive_id');
            }
            if (Schema::connection('tenant')->hasColumn('stock_receive_details', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
