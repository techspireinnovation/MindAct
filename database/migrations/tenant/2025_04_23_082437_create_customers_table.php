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
        Schema::connection('tenant')->create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('party_name');
            $table->string('billing_address')->nullable();
            $table->string('opening_balance')->nullable();
            $table->string('district')->nullable();
            $table->string('vdc_municipality')->nullable();
            $table->text('pan_number')->nullable();
            $table->enum('ledger_type', ['customer', 'vendor', 'both']);
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
        // Disable foreign key constraints
        Schema::connection('tenant')->disableForeignKeyConstraints();

        // Drop the table
        Schema::connection('tenant')->dropIfExists('customers');

        // Re-enable foreign key constraints
        Schema::connection('tenant')->enableForeignKeyConstraints();

    }
};
