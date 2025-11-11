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
       Schema::connection('tenant')->table('voucher_summaries', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('voucher_summaries', 'payment_type')) {
                $table->string('payment_type', 50)->nullable();
            }
            if (!Schema::connection('tenant')->hasColumn('voucher_summaries', 'account_group_id')) {
                $table->foreignId('account_group_id')->nullable()->constrained('account_groups');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::connection('tenant')->dropIfExists('payment_type');
       Schema::connection('tenant')->dropIfExists('account_group_id');
    }
};
