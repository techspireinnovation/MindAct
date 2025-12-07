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
        Schema::connection('tenant')->create('shrinking_working_losses', function (Blueprint $table) {
            $table->id();
           
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->foreignId('product_id')->constrained('products')->nullable();
            $table->double('shrinking_loss_percent')->nullable();
            $table->double('working_loss_percent')->nullable();
            $table->double('internal_loss_percent')->nullable();
            $table->text('adjustment_ref_no')->nullable();
            $table->json('product_details')->nullable();
            $table->double('total_purchase_quantity')->nullable();
            $table->double('total_loss_quantity')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('shrinking_working_losses');
    }
};
