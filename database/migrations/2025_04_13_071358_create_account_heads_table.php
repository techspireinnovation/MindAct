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
        Schema::create('account_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained();
            $table->string('name');
            $table->foreignID('account_group_id')->constrained('account_groups');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
             $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_heads');
    }
};
