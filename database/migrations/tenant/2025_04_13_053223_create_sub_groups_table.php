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
        Schema::connection('tenant')->create('sub_groups', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('main_group_id')->constrained('main_groups');
            $table->string('code')->nullable();
            $table->string('ranking_for_trial')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->auditFields();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sub_groups');
    }
};
