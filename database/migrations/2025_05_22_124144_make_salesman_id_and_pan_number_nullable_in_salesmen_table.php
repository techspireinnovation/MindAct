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
        Schema::table('salesmen', function (Blueprint $table) {
            $table->string('salesman_id')->nullable()->change();
            $table->string('pan_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->string('salesman_id')->nullable(false)->change();
            $table->string('pan_number')->nullable(false)->change();
        });
    }
};
