<?php

namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\StockMovement;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\Log;
use App\Services\UnitConversionService;
use App\Services\QuantityIndexService;
use App\Models\StockProductFieldValue;

use App\Interfaces\StockPurchaseRepositoryInterface;

class StockPurchaseRepository implements StockPurchaseRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityIndexService;

    public function __construct(UnitConversionService $unitConversionService, QuantityIndexService $quantityIndexService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->quantityIndexService = $quantityIndexService;
    }
    public function create(array $data)
    {

        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');


        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase',
            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
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

        $stock = Stock::create($stockData);


        foreach ($data['stock_products'] as $product) {


            $quantity = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'],
                $product['quantity']
            );


            $stockProduct = StockProduct::create([
                'stock_id' => $stock->id,
                'fiscal_year_id' => $fiscalYearId,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'purchase',
                'quantity' => $quantity,
                'is_vatable' => $product['is_vatable'],
                'stock_type' => $product['stock_type'] ?? null,
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
            ]);


            $stockMovementProduct = null;
            if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['free_quantity']
                );

                $stockMovementProduct = StockMovement::create([
                    'stock_id' => $stock->id,
                    'stock_product_id' => $stockProduct->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'company_id' => $data['company_id'],
                    'branch_id' => $data['branch_id'],
                    'product_id' => $product['product_id'],
                    'measure_unit_id' => $product['measure_unit_id'],
                    'type' => 'purchase',
                    'quantity' => $freeQuantity,
                    'stock_type' => 'free',
                    'is_vatable' => $product['is_vatable'],
                    'direction' => $product['direction'] ?? 'in',
                    'party_id' => $data['party_id'] ?? null,
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'mfd' => $product['mfd'] ?? null,
                    'price' => $product['price'] ?? 0,
                    'discount_percent' => $product['discount_percent'] ?? 0,
                    'discount_amount' => $product['discount_amount'] ?? 0,
                    'amount' => $product['amount'] ?? 0,
                    'batch_no' => $product['batch_no'] ?? null,
                ]);
            }


            if (!empty($product['field_values'])) {



                foreach ($product['field_values'] as $quantityIndex => $group) {

                    foreach ($group as $field) {

                        $isFree = ($field['quantity_type'] ?? 'regular') === 'free';

                        StockProductFieldValue::create([
                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProduct->id,
                            'stock_movement_id' => $isFree ? $stockMovementProduct->id ?? null : null,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'quantity_type' => $field['quantity_type'] ?? 'regular',
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

        $stock = Stock::with('stockProducts.stockProductFieldValues')
            ->findOrFail($id);

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $stock->update([
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase',
            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
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
        ]);

        $incomingProductIds = [];

        foreach ($data['stock_products'] ?? [] as $product) {


            $baseQty = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'] ?? null,
                $product['quantity'] ?? 0
            );

            $stockProduct = StockProduct::updateOrCreate(
                [
                    'id' => $product['id'] ?? null,
                ],
                [
                    'stock_id' => $stock->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'company_id' => $data['company_id'],
                    'branch_id' => $data['branch_id'],
                    'product_id' => $product['product_id'],
                    'measure_unit_id' => $product['measure_unit_id'],
                    'type' => 'purchase',
                    'quantity' => $baseQty,
                    'is_vatable' => $product['is_vatable'] ?? 0,
                    'stock_type' => $product['stock_type'] ?? null,
                    'direction' => 'in',
                    'party_id' => $product['party_id'] ?? $data['party_id'] ?? null,
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'mfd' => $product['mfd'] ?? null,
                    'price' => $product['price'] ?? 0,
                    'discount_percent' => $product['discount_percent'] ?? 0,
                    'discount_amount' => $product['discount_amount'] ?? 0,
                    'amount' => $product['amount'] ?? 0,
                    'batch_no' => $product['batch_no'] ?? null,
                ]
            );

            $incomingProductIds[] = $stockProduct->id;

            \Log::info('StockProduct upsert', [
                'sent_id' => $product['id'] ?? 'NEW',
                'result_id' => $stockProduct->id,
                'was_created' => $stockProduct->wasRecentlyCreated ? 'YES' : 'NO',
                'quantity_sent' => $product['quantity'] ?? null,
                'quantity_used' => $baseQty,
            ]);


            $movement = null;
            $freeQtyInput = $product['free_quantity'] ?? 0;

            if ($freeQtyInput > 0) {

                $freeQty = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'] ?? null,
                    $freeQtyInput
                );

                $movement = StockMovement::updateOrCreate(
                    [
                        'stock_product_id' => $stockProduct->id,
                        'stock_type' => 'free',
                    ],
                    [
                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase',
                        'stock_type' => 'free',
                        'quantity' => $freeQty,
                        'direction' => 'in',
                        'party_id' => $product['party_id'] ?? $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $product['price'] ?? 0,
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'amount' => $product['amount'] ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,
                        'is_vatable' => $product['is_vatable'] ?? 0,
                    ]
                );
            } else {

                StockMovement::where('stock_product_id', $stockProduct->id)
                    ->where('stock_type', 'free')
                    ->delete();
            }


            $incomingFieldIds = [];

            if (!empty($product['field_values']) || !empty($product['stock_product_field_values'])) {
                $fieldValues = $product['field_values'] ?? $product['stock_product_field_values'] ?? [];

                foreach ($fieldValues as $group) {


                    $groupIndex = null;


                    foreach ($group as $field) {
                        if (isset($field['quantity_index'])) {
                            $groupIndex = (int) $field['quantity_index'];
                            break;
                        }
                    }


                    if ($groupIndex === null) {
                        $groupIndex = $this->quantityIndexService->getNextQuantityIndex($stockProduct->id);
                    }


                    foreach ($group as $field) {
                        $isFree = ($field['quantity_type'] ?? 'regular') === 'free';

                        $fieldValue = StockProductFieldValue::updateOrCreate(
                            [
                                'id' => $field['id'] ?? null,
                            ],
                            [
                                'stock_id' => $stock->id,
                                'company_id' => $data['company_id'],
                                'branch_id' => $data['branch_id'],
                                'stock_product_id' => $stockProduct->id,
                                'stock_movement_id' => $isFree ? ($movement?->id ?? null) : null,
                                'product_id' => $stockProduct->product_id,
                                'quantity_index' => $groupIndex,
                                'quantity_type' => $field['quantity_type'] ?? 'regular',
                                'key' => $field['key'],
                                'value' => $field['value'],
                            ]
                        );

                        $incomingFieldIds[] = $fieldValue->id;
                    }
                }

            }


            StockProductFieldValue::where('stock_product_id', $stockProduct->id)
                ->when($incomingFieldIds, fn($q) => $q->whereNotIn('id', $incomingFieldIds))
                ->delete();
        }


        $stock->stockProducts()
            ->whereNotIn('id', $incomingProductIds)
            ->delete();

        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');
    }




    public function list(array $filters)
    {

    }

    public function show($id)
    {
        $stock = Stock::with([
            'stockProducts' => function ($query) {
                $query->whereNull('deleted_at')
                    ->with([
                        'stockProductFieldValues' => function ($q) {
                            $q->whereNull('deleted_at');
                        },
                        'stockMovements' => function ($q) {
                            $q->where('type', 'purchase')
                                ->where('stock_type', 'free')
                                ->whereNull('deleted_at');
                        }
                    ]);
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase')
            ->findOrFail($id);


        $stock->stockProducts->transform(function ($product) {

            $freeQty = $product->stockMovements->sum('quantity') ?? 0;


            $attributes = $product->toArray();


            $fieldValues = $attributes['stock_product_field_values'] ?? [];

            unset($attributes['stock_product_field_values']);
            unset($attributes['stock_movements']);


            $newProduct = [];

            foreach ($attributes as $key => $value) {

                $newProduct[$key] = $value;


                if ($key === 'quantity') {
                    $newProduct['free_quantity'] = $freeQty;
                }
            }


            $newProduct['field_values'] = $fieldValues;

            return $newProduct;
        });

        return $stock;
    }


    public function delete($id)
    {

        DB::beginTransaction();

        $stock = Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockProductIds = StockProduct::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->delete();

        StockMovement::whereIn('stock_product_id', $stockProductIds)
            ->where('type', 'purchase')
            ->where('stock_type', 'free')
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