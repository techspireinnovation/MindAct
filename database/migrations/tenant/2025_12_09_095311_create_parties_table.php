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
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('billing_address')->nullable();
            $table->string('opening_balance')->nullable();
            $table->string('district')->nullable();
            $table->string('vdc_municipality')->nullable();
            $table->text('pan_number')->nullable();
            $table->tinyInteger('type')->default(1)->comment('1 = Customer, 2 = Supplier, 3 = Both');
            $table->tinyInteger('balance_type')->nullable()
                ->default(null)->comment('1 = Debit, 2 = Credit');
            $table->text('address')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_phone')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('bank_account_number')->nullable();

            $table->boolean('is_active')->default(true);

            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};


