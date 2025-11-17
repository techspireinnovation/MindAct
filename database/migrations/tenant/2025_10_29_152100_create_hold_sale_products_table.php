<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('hold_sale_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('hold_sale_id')->constrained('hold_sales');
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedBigInteger('purchase_stock_product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('mfd')->nullable();
            $table->date('expiry_date')->nullable();

            $table->foreignId('measure_unit_id')->nullable()->constrained('measure_units');
            $table->string('quantity')->nullable();
            $table->string('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->boolean('is_vatable')->default(true);
            $table->double('amount')->nullable();
            $table->softDeletes();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('hold_sale_products');
    }
};
