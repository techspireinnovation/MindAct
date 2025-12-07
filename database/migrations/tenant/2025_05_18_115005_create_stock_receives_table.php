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
        Schema::connection('tenant')->create('stock_receives', function (Blueprint $table) {
            $table->id();
           
            $table->string('transfer_ref_no')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('receive_from')->nullable();
            $table->string('current_location')->nullable();
            $table->string('address')->nullable();
            $table->string('document_no')->nullable();
            $table->date('current_date')->nullable();
            $table->string('current_date_bs')->nullable();
            $table->date('stock_transfer_date')->nullable();
            $table->string('stock_transfer_date_bs')->nullable();
            $table->json('product_details')->nullable();
            $table->string('reasons')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('stock_receives');
    }
};
