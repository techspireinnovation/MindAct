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
        Schema::connection('tenant')->create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('personal_access_tokens')->onDelete('cascade');
            $table->string('type', length: 20);
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->auditFields();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('notifications');
    }
};
