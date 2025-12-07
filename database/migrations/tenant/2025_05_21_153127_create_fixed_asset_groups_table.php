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
        Schema::connection('tenant')->create('fixed_asset_groups', function (Blueprint $table) {
            $table->id();
            
            $table->foreignID('account_group_id')->constrained('account_groups')->nullable();
            $table->text('name');
            $table->text('code')->nullable();
            $table->double('depreciation_percent')->nullable();
            $table->boolean('status')->nullable();
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
        Schema::connection('tenant')->dropIfExists('fixed_asset_groups');
    }
};
