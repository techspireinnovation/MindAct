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
       Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            $table->unique(['ref_bill_number', 'company_id'], 'purchases_ref_bill_number_company_id_unique');
            $table->unique(['purchase_bill_number', 'company_id'], 'purchases_purchase_bill_number_company_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_ref_bill_number_company_id_unique');
            $table->dropUnique('purchases_purchase_bill_number_company_id_unique');
        });
    }
};
