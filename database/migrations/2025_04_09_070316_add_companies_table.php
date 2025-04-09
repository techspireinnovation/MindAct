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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('fiscal_year');
            $table->date('licence_issue_date')->nullable();
            $table->date('working_date')->nullable();
            $table->string('reg_number')->nullable();
            $table->string('pan_number')->nullable();
            $table->string('full_address')->nullable();
            $table->email('email_address')->nullable();
            $table->string('website')->nullable();
            $table->string('fax')->nullable();
            $table->string('logo')->nullable();
            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('palika_name')->nullable();
            $table->string('ward_number')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_position')->nullable();
            $table->string('agreement_holder_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->string('license_number')->nullable();
            $table->string('activation_key')->nullable();
            $table->string('url_link')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
