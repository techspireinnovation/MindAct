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
             $table->unsignedBigInteger('bank_id')->nullable()->after('id'); 

        // If you want to enforce foreign key constraint:
           $table->foreign('bank_id')->references('id')->on('banks')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('purchases', function (Blueprint $table) {
            $table->dropForeign(['bank_id']); // Drop foreign key constraint
            $table->dropColumn('bank_id'); // Drop the column
        });
    }
};
