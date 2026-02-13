<?php

namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Services\UnitConversionService;
use App\Models\StockProductFieldValue;

use App\Interfaces\StockRepositoryInterface;

class StockRepository implements StockRepositoryInterface
{

    protected $unitConversionService;

    public function __construct(UnitConversionService $unitConversionService)
    {
        $this->unitConversionService = $unitConversionService;
    }
    public function create(array $data)
    {

        DB::beginTransaction();

        $stock = Stock::create([
            'fiscal_year_id' => '1',
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'type' => 'opening_stock',
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,
        ]);


        foreach ($data['stock_products'] as $product) {

            $quantity = $this->unitConversionService->convertToBaseUnit(

                $product['measure_unit_id'],
                $product['quantity']
            );


            $stockProduct = StockProduct::create([
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                'is_vatable' => $product['is_vatable'],

                'stock_type' => $product['stock_type'] ?? null,
            ]);


            if (!empty($product['field_values'])) {

                foreach ($product['field_values'] as $quantityIndex => $group) {
                    foreach ($group as $field) {

                        StockProductFieldValue::create([
                            'stock_product_id' => $stockProduct->id,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'key' => $field['key'],
                            'value' => $field['value'],
                        ]);
                    }
                }
            }
        }
        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');



    }

    public function update($id, array $data)
    {
        DB::beginTransaction();
        $stock = Stock::findOrFail($id);


        $stock->update([
            'fiscal_year_id' => 1,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        $incomingProductIds = collect($data['stock_products'])
            ->pluck('id')
            ->filter()
            ->toArray();


        $stock->stockProducts()
            ->whereNotIn('id', $incomingProductIds)
            ->delete();

        foreach ($data['stock_products'] as $product) {
            $quantity = $this->unitConversionService->convertToBaseUnit(

                $product['measure_unit_id'],
                $product['quantity']
            );


            $stockProduct = StockProduct::updateOrCreate([

                'id' => $product['id'] ?? null,
                'stock_id' => $stock->id,

            ], [
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                // 'is_vatable' => $product['is_vatable'],

                'stock_type' => $product['stock_type'] ?? null,
            ]);

            $incomingFieldValueIds = [];


            if (!empty($product['field_values'])) {

                foreach ($product['field_values'] as $quantityIndex => $group) {
                    foreach ($group as $field) {

                        $fieldValue = StockProductFieldValue::updateOrCreate([
                            'id' => $field['id'] ?? null,
                            'stock_product_id' => $stockProduct->id,

                        ], [
                            'stock_product_id' => $stockProduct->id,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'key' => $field['key'],
                            'value' => $field['value'],
                        ]);
                        $incomingFieldValueIds[] = $fieldValue->id;
                    }
                }
            }

            $stockProduct->stockProductFieldValues()
                ->when(!empty($incomingFieldValueIds), function ($query) use ($incomingFieldValueIds) {
                    $query->whereNotIn('id', $incomingFieldValueIds);
                })
                ->delete();
        }


        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');

    }

    public function list(array $filters)
    {

    }

    public function show($id)
    {

        $stock = Stock::with('stockProducts.stockProductFieldValues')->whereNull('deleted_at')->findOrFail($id);
        return $stock;



    }

    public function delete($id)
    {

    }
}

?>