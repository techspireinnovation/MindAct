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
        Schema::create('production_assembles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('production_id')->nullable();
            $table->string('product_name')->nullable();
            $table->foreignId('measure_unit_id')->constrained('measure_units')->nullable();
            $table->double('product_quantity')->nullable();             
            $table->date('production_date')->nullable();
            $table->string('production_no')->nullable();
            $table->foreignId('product_location_id')->constrained('locations')->nullable();
            $table->string('document_no')->nullable();
            $table->string('batch_no')->nullable();
            $table->json('product_details')->nullable();
            $table->double('total_rm_amount')->nullable();
            $table->double('product_damage_quantity')->nullable();
            $table->double('finish_product_qauntity')->nullable();
            $table->double('finish_cost_per_unit')->nullable();
            $table->double('total_product_cost')->nullable();
            $table->text('remarks')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_assembles');
    }
};
