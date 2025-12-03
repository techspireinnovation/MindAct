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
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            
            // Store the USER ID from MAIN database (just store the ID, no foreign key)
            $table->unsignedBigInteger('user_id')->index();
            
            // Admin profile data (all stored in TENANT database)
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone_number');
            $table->text('address');
            $table->string('citizenship_number')->unique();
            $table->string('pan_number')->unique()->nullable();
            $table->string('role')->default('admin');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('citizenship_number');
            $table->index('pan_number');
            $table->index('role');
            $table->index(['user_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};