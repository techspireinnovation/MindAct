<?php

namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\FiscalYear;
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
        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $stockValidated = [

            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'type' => 'opening_stock',
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,

            'store_id' => $data['store_id'] ?? null,

            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_after_vat' => $data['discount_after_vat'] ?? 0,
            'sub_total_before_discount' => $data['sub_total_before_discount'] ?? 0,
            'taxable_amount' => $data['taxable_amount'] ?? 0,
            'non_taxable_amount' => $data['non_taxable_amount'] ?? 0,
            'excise_duty' => $data['excise_duty'] ?? 0,
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $data['health_insurance'] ?? 0,
            'freight_amount' => $data['freight_amount'] ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $data['roundoff_amount'] ?? 0,
            'total_amount' => $data['total_amount'] ?? 0,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];

        $stock = Stock::create($stockValidated);


        foreach ($data['stock_products'] as $product) {

            $quantity = $this->unitConversionService->convertToBaseUnit(

                $product['measure_unit_id'],
                $product['quantity']
            );


            $productValidated = [
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                'is_vatable' => $product['is_vatable'],

                'stock_type' => $product['stock_type'] ?? null,

                'fiscal_year_id' => $fiscalYearId,

                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'direction' => $product['direction'] ?? 'in',
                'party_id' => $data['party_id'] ?? null,
                'expiry_date' => $product['expiry_date'] ?? null,
                'mfd' => $product['mfd'] ?? null,
                'price' => $product['price'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'amount' => $product['amount'] ?? 0,
                'batch_no' => $product['batch_no'] ?? null,
            ];


            $stockProduct = StockProduct::create($productValidated);


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

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $stockValidated = [
            'fiscal_year_id' => $fiscalYearId,

            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,
            'type' => 'opening_stock',
            'store_id' => $data['store_id'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_after_vat' => $data['discount_after_vat'] ?? 0,
            'sub_total_before_discount' => $data['sub_total_before_discount'] ?? 0,
            'taxable_amount' => $data['taxable_amount'] ?? 0,
            'non_taxable_amount' => $data['non_taxable_amount'] ?? 0,
            'excise_duty' => $data['excise_duty'] ?? 0,
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $data['health_insurance'] ?? 0,
            'freight_amount' => $data['freight_amount'] ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $data['roundoff_amount'] ?? 0,
            'total_amount' => $data['total_amount'] ?? 0,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];

        $stock->update($stockValidated);

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

            $stockProductValidated = [
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                'stock_type' => $product['stock_type'] ?? null,
                'is_vatable' => $product['is_vatable'],
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'direction' => $product['direction'] ?? 'in',
                'party_id' => $data['party_id'] ?? null,
                'expiry_date' => $product['expiry_date'] ?? null,
                'mfd' => $product['mfd'] ?? null,
                'price' => $product['price'] ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'discount_amount' => $product['discount_amount'] ?? 0,
                'amount' => $product['amount'] ?? 0,
                'batch_no' => $product['batch_no'] ?? null,

            ];

            $stockProduct = StockProduct::updateOrCreate([

                'id' => $product['id'] ?? null,
                'stock_id' => $stock->id,

            ], $stockProductValidated);

            $incomingFieldValueIds = [];


            if (!empty($product['field_values'])) {

                foreach ($product['field_values'] as $quantityIndex => $group) {
                    foreach ($group as $field) {

                        $fieldValuesValidated = [
                            'stock_product_id' => $stockProduct->id,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'key' => $field['key'],
                            'value' => $field['value'],

                        ];

                        $fieldValue = StockProductFieldValue::updateOrCreate([
                            'id' => $field['id'] ?? null,
                            'stock_product_id' => $stockProduct->id,

                        ], $fieldValuesValidated);
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
        return Stock::where('type','opening_stock')->whereNull('deleted_at')->get();

    }

    public function show($id)
    {

        $stock = Stock::with('stockProducts.stockProductFieldValues')->whereNull('deleted_at')->findOrFail($id);
        return $stock;



    }

    public function delete($id)
    {
        DB::beginTransaction();

        $stock = Stock::where('type', 'opening_stock')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockProductIds = StockProduct::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->delete();

        
        StockProduct::whereIn('id', $stockProductIds)
            ->delete();

        $stock->delete();
        DB::commit();
        return true;


    }
}

?>