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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('party_name');
            $table->string('billing_address')->nullable();
            $table->string('opening_balance')->nullable();
            $table->string('district')->nullable();
            $table->text('pan_number')->nullable();
            $table->enum('ledger_type',['customer','vendor','both']);
            $table->text('address')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_phone')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();                              
            $table->boolean('is_active')->default(true);
            $table->SoftDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
