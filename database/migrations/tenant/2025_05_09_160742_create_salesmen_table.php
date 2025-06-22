
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
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->foreignID('company_id')->constrained('companies');
            $table->text('salesman_id');
            $table->string('pan_number');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('working_office')->nullable();
            $table->string('joining_date')->nullable();
            $table->string('designation')->nullable();
            $table->string('dob')->nullable();
            $table->string('citizenship_number')->nullable();
            $table->string('nationality')->nullable();
            $table->string('zone')->nullable();
            $table->string('district')->nullable();
            $table->string('vdc/municipality')->nullable();
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
