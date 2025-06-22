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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->text('name');
            $table->string('contact_details')->nullable();
            $table->date('starting_date')->nullable();
            $table->date('ending_date')->nullable();
            $table->double('budget')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('contact_number')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->SoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
