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
            
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            
            $table->integer('ward_no')->nullable();
            $table->string('area')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
             $table->dropColumn([
                'country', 
                'state', 
                
                'ward_no', 
                'area'
            ]);
        });
    }
};
