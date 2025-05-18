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
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('mobile')->nullable();
            $table->string('working_offce')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('desigation')->nullable();
            $table->date('dob')->nullable();
            $table->string('citizenship_number')->nullable();
            $table->string('nationality')->nullable();
            $table->string('zone')->nullable();
            $table->string('vdc_municipality')->nullable();
            $table->string('pan_number')->nullable();
            $table->string('district')->nullable();
            $table->foreignID('company_id')->constrained();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salesmen');
    }
};
