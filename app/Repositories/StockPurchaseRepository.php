<?php

namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use App\Services\TransactionImplementService;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Models\StockMovement;
use App\Models\FiscalYear;
use App\Models\Vat;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use Illuminate\Support\Facades\Log;
use App\Services\UnitConversionService;
use App\Services\QuantityIndexService;
use App\Services\CurrencyFormatService;
use App\Services\DateFormatService;
use App\Models\StockProductFieldValue;

use App\Interfaces\StockPurchaseRepositoryInterface;

class StockPurchaseRepository implements StockPurchaseRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityIndexService;

    protected $currencyFormatService;

    protected $dateFormatService;

    protected $taxImplementService;

    public function __construct(
        UnitConversionService $unitConversionService,
        QuantityIndexService $quantityIndexService,
        CurrencyFormatService $currencyFormatService,
        TransactionImplementService $taxImplementService,

    ) {
        $this->unitConversionService = $unitConversionService;
        $this->quantityIndexService = $quantityIndexService;
        $this->currencyFormatService = $currencyFormatService;

        $this->taxImplementService = $taxImplementService;
    }
    public function create(array $data)
    {

        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;



        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase',
            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? 0,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? 0,
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
            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock = Stock::create($stockData);


        foreach ($data['stock_products'] as $product) {

            //@todo: move this logic to a service class and inject here for better separation of concerns

            //check if there is quantity and measure unit, if not skip the product


            $quantity = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'],
                $product['quantity']
            );

            $stockValidated = [

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
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                'batch_no' => $product['batch_no'] ?? null,

            ];


            $stockProduct = StockProduct::create($stockValidated);


            $stockMovementProduct = null;
            if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['free_quantity']
                );

                $movementValidated = [
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
                    'price' => null,
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'amount' => null,
                    'batch_no' => $product['batch_no'] ?? null,
                ];

                $stockMovementProduct = StockMovement::create($movementValidated);
            }


            if (!empty($product['field_values'])) {



                foreach ($product['field_values'] as $quantityIndex => $group) {

                    foreach ($group as $field) {

                        $isFree = ($field['quantity_type'] ?? 'regular') === 'free';
                        $fieldValueData = [

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

                        ];

                        StockProductFieldValue::create($fieldValueData);


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

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;


        $stockValidated = [
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
            'balance' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
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
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,

            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock->update($stockValidated);

        $incomingProductIds = [];

        foreach ($data['stock_products'] ?? [] as $product) {


            $baseQty = $this->unitConversionService->convertToBaseUnit(
                $product['measure_unit_id'] ?? null,
                $product['quantity'] ?? 0
            );

            $stockProductData = [

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
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                'batch_no' => $product['batch_no'] ?? null,
            ];

            $stockProduct = StockProduct::updateOrCreate(
                [
                    'id' => $product['id'] ?? null,
                ],
                $stockProductData
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

                $movementValidatedData = [

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
                    'price' => null,
                    'discount_percent' => null,
                    'discount_amount' => null,
                    'amount' => null,
                    'batch_no' => $product['batch_no'] ?? null,
                    'is_vatable' => $product['is_vatable'] ?? 0,


                ];

                $movement = StockMovement::updateOrCreate(
                    [
                        'stock_product_id' => $stockProduct->id,
                        'stock_type' => 'free',
                    ],
                    $movementValidatedData
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

                        $fieldValueData = [

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

                        ];

                        $fieldValue = StockProductFieldValue::updateOrCreate(
                            [
                                'id' => $field['id'] ?? null,
                            ],
                            $fieldValueData
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
        return Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->with('party')                    // Eager load the party relationship
            ->get()
            ->map(function ($stock) {
                return [
                    'id' => $stock->id,
                    'fiscal_year_id' => $stock->fiscal_year_id,
                    'bank_id' => $stock->bank_id ?? null,
                    'branch_id' => $stock->branch_id ?? null,
                    'party_id' => $stock->party_id ?? null,
                    'party_name' => $stock->party?->name ?? 'N/A',   // ← This is what you want
                    'location_id' => $stock->location_id ?? null,
                    'type' => $stock->type ?? null,
                    'company_id' => $stock->company_id,
                    'store_id' => $stock->store_id ?? null,
                    'invoice_date' => $stock->invoice_date ?? null,
                    'invoice_date_bs' => $stock->invoice_date_bs ?? null,
                    'bill_number' => $stock->bill_number ?? null,
                    'ref_bill_number' => $stock->ref_bill_number ?? null,
                    'reasons' => $stock->reasons ?? null,
                    'discount_type' => $stock->discount_type ?? null,
                    'discount_value' => $stock->discount_value ?? null,
                    'discount_after_vat' => $stock->discount_after_vat ?? null,
                    'sub_total_before_discount' => $stock->sub_total_before_discount ?? null,
                    'excise_duty' => $stock->excise_duty ?? null,
                    'vat_percent' => $stock->vat_percent ?? null,
                    'health_insurance' => $stock->health_insurance ?? null,
                    'freight_amount' => $stock->freight_amount ?? null,
                    'roundoff_type' => $stock->roundoff_type ?? null,
                    'roundoff_amount' => $stock->roundoff_amount ?? null,
                    'payment' => $stock->payment ? json_decode($stock->payment, true) : null,
                    'taxable_amount' => $stock->taxable_amount ?? null,
                    'non_taxable_amount' => $stock->non_taxable_amount ?? null,
                    'total_amount' => $stock->total_amount ?? null,
                    'remarks' => $stock->remarks,
                    'created_at' => $stock->created_at,
                    // Add any other fields you need...
                ];
            });
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
                        },

                    ]);
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase')
            ->findOrFail($id);

        $stock->stockProducts->transform(function ($product) {

            /*
            |---------------------------------------------
            | FREE QUANTITY
            |---------------------------------------------
            */
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

            /*
            |---------------------------------------------
            | PRODUCT NAME
            |---------------------------------------------
            */
            $newProduct['product_name'] = $product->product->name ?? null;

            /*
            |---------------------------------------------
            | CONFIG LOAD
            |---------------------------------------------
            */
            $productFieldNumber = $product->product->product_field_number ?? null;

            $config = $productFieldNumber
                ? config("product_fields.{$productFieldNumber}")
                : null;

            $configFields = collect($config['fields'] ?? [])
                ->keyBy('key');

            // $configFields = collect($config['fields'] ?? [])
            //     ->mapWithKeys(function ($field) {

            //         
            //         $key = strtolower($field['name'] ?? $field['label'] ?? '');

            //         return [$key => $field];
            //     });


            $newProduct['field_values'] = collect($fieldValues)
                ->map(function ($item) use ($configFields) {

                    $fieldConfig = $configFields[$item['key']] ?? null;

                    $isDropdown = ($fieldConfig['type'] ?? null) == 'dropdown';

                    return [

                        'id' => $item['id'] ?? null,
                        'stock_id' => $item['stock_id'] ?? null,
                        'company_id' => $item['company_id'] ?? null,
                        'branch_id' => $item['branch_id'] ?? null,
                        'stock_product_id' => $item['stock_product_id'] ?? null,
                        'stock_movement_id' => $item['stock_movement_id'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity_index' => $item['quantity_index'] ?? null,
                        'quantity_type' => $item['quantity_type'] ?? null,
                        'key' => $item['key'] ?? null,
                        'value' => $item['value'] ?? null,
                        'created_at' => $item['created_at'] ?? null,
                        'updated_at' => $item['updated_at'] ?? null,
                        'deleted_at' => $item['deleted_at'] ?? null,


                        'type' => $fieldConfig['type'] ?? 'text',
                        'options' => $isDropdown ? ($fieldConfig['options'] ?? []) : null,
                    ];
                })
                ->values()
                ->toArray();


            $productId = $product->product_id;

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

            $newProduct['measure_units'] = $measureUnits;

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