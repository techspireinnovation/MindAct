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
       Schema::connection('tenant')->table('projects', function (Blueprint $table) {
            $table->renameColumn('contact_details', 'contact_person');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('projects', function (Blueprint $table) {
            $table->renameColumn('contact_person', 'contact_details');
        });
    }
};
