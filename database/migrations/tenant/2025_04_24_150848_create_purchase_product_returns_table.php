`
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
        Schema::connection('tenant')->create('purchase_product_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignID(column: 'purchase_return_id')->constrained('purchase_returns')->onDelete('cascade');
            $table->foreignID(column: 'product_id')->constrained('products');
            $table->unsignedBigInteger(column: 'party_id')->nullable();
            $table->foreignID(column: 'purchase_product_id')->constrained('purchase_products');
            $table->string(column: 'product_name')->constrained('products');
            $table->text(column: 'purchase_product_code')->nullable();
            $table->date('expiry_date')->nullable();
            $table->double('quantity')->nullable();
            $table->double('free_quantity')->nullable();
            $table->double('price')->nullable();
            $table->double('discount_percent')->nullable();
            $table->double('discount_amount')->nullable();
            $table->double('amount')->nullable();
            $table->boolean('is_vatable')->nullable();
            $table->foreignID(column: 'measure_unit_id')->constrained('measure_units');
            $table->auditFields();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('purchase_product_returns');
    }
};
