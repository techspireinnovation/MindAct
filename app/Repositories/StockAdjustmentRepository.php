<?php

namespace App\Repositories;
use App\Models\StockMovement;
use App\Models\TransactionPivot;
use App\Services\TransactionImplementService;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\Vat;
use App\Models\StockProduct;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\ProductList;
use App\Services\UnitConversionService;
use App\Services\CurrencyFormatService;
use App\Services\QuantityAllocationService;
use App\Models\StockProductFieldValue;
use Exception;

use App\Interfaces\StockAdjustmentRepositoryInterface;

class StockAdjustmentRepository implements StockAdjustmentRepositoryInterface
{

    protected $unitConversionService;

    protected $quantityAllocationService;
    protected $currencyFormatService;
    protected $taxImplementService;

    public function __construct(UnitConversionService $unitConversionService, CurrencyFormatService $currencyFormatService, TransactionImplementService $taxImplementService, QuantityAllocationService $quantityAllocationService, )
    {
        $this->unitConversionService = $unitConversionService;
        $this->currencyFormatService = $currencyFormatService;
        $this->taxImplementService = $taxImplementService;
    }
    public function create(array $data)
    {
        DB::beginTransaction();

        try {

            $fiscalYearId = FiscalYear::where('status', 1)
                ->whereNull('deleted_at')
                ->value('id');

            $appliedVat = Vat::where('is_active', 1)->value('vat_percent') ?? 0;

            $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0;
            $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0;
            $vatAmount = $this->taxImplementService->transactionImplement($appliedVat, $taxableAmount) ?? 0;

            $stockData = [
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'store_id' => $data['store_id'] ?? null,
                'type' => 'stock_adjustment',
                'bill_number' => $data['bill_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
                'party_id' => $data['party_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'batch_no' => $data['batch_no'] ?? null,
                'credit_days' => $data['credit_days'] ?? null,
                'balance' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
                'ref_bill_number' => $data['ref_bill_number'] ?? null,
                'return_bill_no' => $data['return_bill_no'] ?? null,
                'reasons' => $data['reasons'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
                'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
                'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
                'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
                'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
                'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
                'vat_percent' => $this->currencyFormatService->cleanCurrency($data['vat_percent'] ?? 0) ?? 0,
                'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
                'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
                'roundoff_type' => $data['roundoff_type'] ?? null,
                'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
                'total_amount' => $totalAmount + $vatAmount,
                'payment' => json_encode($data['payment']) ?? null,
                'remarks' => $data['remarks'] ?? null,
            ];

            $stock = Stock::create($stockData);

            foreach ($data['stock_details'] as $product) {

                $fieldValues = $product['field_values'] ?? [];


                if ($product['stock_type'] == "subtract") {


                    if (empty($fieldValues)) {

                        $baseQuantity = $this->unitConversionService->convertToBaseUnit(
                            $product['measure_unit_id'],
                            $product['quantity']
                        );

                        $allocatedQtys = $this->quantityAllocationService
                            ->allocateItemWiseWiseQuantity($product['product_id'], $baseQuantity);

                        $totalAllocated = collect($allocatedQtys)->sum('quantity');

                        if ($totalAllocated < $baseQuantity) {
                            DB::rollBack();
                            throw new Exception('Return quantity exceeds available stock');
                        }

                        foreach ($allocatedQtys as $alloc) {

                            $stockMovementData = [
                                'stock_id' => $stock->id,
                                'fiscal_year_id' => $fiscalYearId,
                                'source_type' => $alloc['source_type'],
                                'source_id' => $alloc['source_id'],
                                'stock_product_id' => $alloc['stock_product_id'],
                                'stock_movement_id' => $alloc['stock_movement_id'] ?? null,
                                'product_id' => $product['product_id'],
                                'measure_unit_id' => $product['measure_unit_id'],
                                'type' => 'stock_adjustment',
                                'quantity' => $alloc['quantity'],
                                'direction' => 'out',
                                'stock_type' => 'subtract',
                                'company_id' => $data['company_id'],
                                'branch_id' => $data['branch_id'],
                            ];

                            StockMovement::create($stockMovementData);
                        }
                    } else {

                        $grouped = [];

                        foreach ($fieldValues as $group) {
                            foreach ($group as $field) {

                                $stockProductId = $field['stock_product_id'] ?? null;
                                $stockMovementId = $field['stock_movement_id'] ?? null;

                                if (!$stockProductId && !$stockMovementId) {
                                    throw new Exception('Either stock_product_id or stock_movement_id is required');
                                }

                                $groupKey = $stockProductId ?: 'sm_' . $stockMovementId;

                                if (!isset($grouped[$groupKey])) {
                                    $grouped[$groupKey] = [
                                        'stock_product_id' => $stockProductId,
                                        'stock_movement_id' => $stockMovementId,
                                        'quantity' => 0,
                                        'fields' => []
                                    ];
                                }

                                $grouped[$groupKey]['quantity']++;
                                $grouped[$groupKey]['fields'][] = $field;
                            }
                        }

                        foreach ($grouped as $groupKey => $dataGroup) {

                            $stockProductId = $dataGroup['stock_product_id'];
                            $stockMovementId = $dataGroup['stock_movement_id'];

                            $sourceType = $stockMovementId ? 'stock_movement' : 'stock_product';
                            $sourceId = $stockMovementId ?: $stockProductId;
                            $stockDataMovement =
                                [
                                    'stock_id' => $stock->id,
                                    'fiscal_year_id' => $fiscalYearId,
                                    'source_type' => $sourceType,
                                    'source_id' => $sourceId,
                                    'stock_product_id' => $stockProductId ?? null,
                                    'stock_movement_id' => $stockMovementId ?? null,
                                    'product_id' => $product['product_id'],
                                    'measure_unit_id' => $product['measure_unit_id'],
                                    'type' => 'stock_adjustment',
                                    'quantity' => $dataGroup['quantity'],
                                    'direction' => 'out',
                                    'stock_type' => 'subtract',
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $data['branch_id'],
                                ];
                            $movement = StockMovement::create($stockDataMovement);

                            foreach ($dataGroup['fields'] as $field) {

                                $transactionPivot = [
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $data['branch_id'],
                                    'type' => 'stock_adjustment',
                                    'direction' => 'out',
                                    'stock_product_id' => $field['stock_product_id'] ?? null,
                                    'stock_movement_id' => $field['stock_movement_id'] ?? null,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => 'regular',

                                ];
                                TransactionPivot::create($transactionPivot);
                            }
                        }
                    }
                } elseif ($product['stock_type'] == "add") {

                    $quantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['quantity']
                    );

                    $movementData = [
                        'stock_id' => $stock->id,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'quantity' => $quantity,
                        'type' => 'stock_adjustment',
                        'direction' => 'in',
                        'stock_type' => 'add',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],

                    ];

                    $movement = StockMovement::create($movementData);

                    if (!empty($fieldValues)) {

                        foreach ($fieldValues as $index => $group) {
                            foreach ($group as $field) {

                                $fieldValues = [
                                    'stock_id' => $stock->id,
                                    'company_id' => $data['company_id'],
                                    'branch_id' => $data['branch_id'],
                                    'stock_movement_id' => $movement->id,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $index,
                                    'quantity_type' => 'regular',
                                    'key' => $field['key'],
                                    'value' => $field['value'],
                                ];

                                StockProductFieldValue::create($fieldValues);
                            }
                        }
                    }
                }
            }

            DB::commit();

            return $stock->load('stockMovements');

        } catch (\Exception $e) {

            DB::rollBack();
            throw $e;
        }
    }

    public function update($id, array $data)
    {
        DB::beginTransaction();
        $stock = Stock::findOrFail($id);

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;


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
            'balance' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
            'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
            'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
            'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
            'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
            'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
            'vat_percent' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
            $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,

            $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,

            $vatAmount = $this->taxImplementService->transactionImplement($appliedVat ?? 0, $taxableAmount) ?? 0,

            'total_amount' => $totalAmount + $vatAmount,
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
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
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
                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
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
        return Stock::where('type', 'stock_adjustment')->whereNull('deleted_at')->get();

    }

    public function show($id)
    {
        $stock = Stock::with('stockMovements.stockProductFieldValues', 'stockMovements.product')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stock->stockProducts->map(function ($stockProduct) {

            $stockProduct->field_values = $stockProduct->stockProductFieldValues->map(function ($item) use ($stockProduct) {

                $productFieldNumber = $stockProduct->product->product_field_number ?? null;

                $config = $productFieldNumber
                    ? config("product_fields.{$productFieldNumber}")
                    : null;

                // find field config by key (IMPORTANT FIX: match by key, not name)
                $fieldConfig = collect($config['fields'] ?? [])
                    ->first(function ($field) use ($item) {
                        return strtolower($field['key'] ?? '') === strtolower($item->key);
                    });

                $type = $fieldConfig['type'] ?? 'text';
                $isDropdown = $type === 'dropdown';

                return [
                    'id' => $item->id,
                    'stock_id' => $item->stock_id,
                    'company_id' => $item->company_id,
                    'branch_id' => $item->branch_id,
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->stock_movement_id,
                    'product_id' => $item->product_id,
                    'quantity_index' => $item->quantity_index,
                    'quantity_type' => $item->quantity_type,
                    'key' => $item->key,
                    'value' => $item->value,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                    'deleted_at' => $item->deleted_at,


                    'type' => $type,
                    'options' => $isDropdown ? ($fieldConfig['options'] ?? []) : null,
                ];
            });

            unset($stockProduct->stockProductFieldValues);

            $stockProduct->product_name = $stockProduct->product->name ?? null;
            unset($stockProduct->product);

            $productId = $stockProduct->product_id;

            $productUnitIds = Product::where('id', $productId)
                ->pluck('measure_unit_id');

            $productListUnitIds = ProductList::where('product_id', $productId)
                ->pluck('measure_unit_id');

            $unitIds = collect()
                ->merge($productUnitIds)
                ->merge($productListUnitIds)
                ->filter()
                ->unique()
                ->values();

            $measureUnits = MeasureUnit::whereIn('id', $unitIds)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity'])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });

            $stockProduct->measure_units = $measureUnits;

            return $stockProduct;
        });

        return $stock;
    }

    public function delete($id)
    {
        DB::beginTransaction();

        $stock = Stock::where('type', 'stock_adjustment')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockMovementIds = StockMovement::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_movement_id', $stockMovementIds)
            ->whereNull('deleted_at')
            ->delete();


        StockMovement::whereIn('id', $stockMovementIds)
            ->delete();

        $stock->delete();
        DB::commit();
        return true;
    }
}
?>