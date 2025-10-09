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
        Schema::create('shrink_work_losses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->nullable();
            $table->foreignId('branch_id')->constrained('branches')->nullable();
            $table->double('shrinking_loss_percent')->nullable();
            $table->double('working_loss_percent')->nullable();
            $table->double('internal_loss_percent')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shrink_work_losses');
    }
};
